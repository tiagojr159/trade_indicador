<?php
declare(strict_types=1);
require_once __DIR__ . '/trade_policy.php';

// All dates use the same clock as the collector and its DATETIME records.
function spPrepare(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        return strcmp((string)$a['created_at'], (string)$b['created_at']);
    });
    $result = [];
    foreach ($rows as $row) {
        $time = strtotime((string)($row['created_at'] ?? ''));
        if ($time === false || !is_numeric($row['btc_price'] ?? null) || (float)$row['btc_price'] <= 0) {
            continue;
        }
        $features = [];
        for ($i = 1; $i <= 50; $i++) {
            $value = $row[sprintf('indicator_%02d', $i)] ?? null;
            $features[] = is_numeric($value) && abs((float)$value) <= 2 ? (float)$value : null;
        }
        $names = $row['indicator_names'] ?? [];
        if (is_string($names)) {
            $names = json_decode($names, true) ?: [];
        }
        $result[] = [
            'time' => $time, 'date' => (string)$row['created_at'],
            'interval' => (string)($row['interval_used'] ?? '1h'),
            'btc' => (float)$row['btc_price'],
            'eth' => is_numeric($row['eth_price'] ?? null) && (float)$row['eth_price'] > 0 ? (float)$row['eth_price'] : null,
            'x' => $features, 'names' => $names,
        ];
    }
    // Causal short-term context helps distinguish identical 1h indicator scores
    // when forecasting a 5m move. Never compare absolute BTC price levels.
    foreach ($result as $i => $row) {
        $context = array_fill(0, 8, null);
        foreach ([300 => .25, 900 => .5, 3600 => 1.0] as $seconds => $scale) {
            $slot = $seconds === 300 ? 0 : ($seconds === 900 ? 1 : 2);
            for ($j = $i-1; $j >= 0; $j--) {
                $past = $result[$j];
                if ($past['time'] > $row['time']-$seconds) continue;
                if ($past['time'] < $row['time']-$seconds-120) break;
                if ($past['interval'] !== $row['interval'] || $past['names'] !== $row['names']) continue;
                $context[$slot] = max(-2, min(2, ($row['btc']/$past['btc']-1)*100/$scale));
                if ($seconds === 300) {
                    for ($g = 0; $g < 5; $g++) {
                        $diff = [];
                        for ($k=$g*10; $k<($g+1)*10; $k++) {
                            if ($row['x'][$k] !== null && $past['x'][$k] !== null) $diff[]=$row['x'][$k]-$past['x'][$k];
                        }
                        if (count($diff)>=8) $context[3+$g]=array_sum($diff)/count($diff)/2;
                    }
                }
                break;
            }
        }
        $result[$i]['context'] = $context;
    }
    return $result;
}

function spLabel(float $return, float $threshold): int
{
    return $return > $threshold ? 2 : ($return < -$threshold ? 0 : 1);
}

function spSamples(array $rows, int $minutes, float $threshold): array
{
    $samples = [];
    $count = count($rows);
    $j = 0;
    $nextOrigin = 0;
    $seconds = $minutes * 60;
    // Match elapsed time, never a row count; reject endpoints across collection gaps.
    $tolerance = min(120, (int)($seconds / 4));
    for ($i = 0; $i < $count; $i++) {
        $row = $rows[$i];
        if ($row['time'] < $nextOrigin || count(array_filter($row['x'], 'is_numeric')) < 40) {
            continue;
        }
        $nextOrigin = $row['time'] + 60;
        $target = $row['time'] + $seconds;
        $j = max($j, $i + 1);
        while ($j < $count && $rows[$j]['time'] < $target) {
            $j++;
        }
        if ($j >= $count || $rows[$j]['time'] - $target > $tolerance) {
            continue;
        }
        $return = ($rows[$j]['btc'] / $row['btc'] - 1) * 100;
        $samples[] = ['time' => $row['time'], 'end' => $rows[$j]['time'], 'btc' => $row['btc'],
            'x' => $row['x'], 'context' => $row['context'] ?? [], 'return' => $return, 'label' => spLabel($return, $threshold)];
    }
    return $samples;
}

function spPredictionConfig(int $minutes, float $threshold): array
{
    // Fixed regularized configuration. Do not tune against the displayed test period.
    return ['k' => 25, 'power' => 1.0, 'margin' => .12, 'halfLife' => 24,
        'requireWinner' => true, 'adaptive' => true, 'context' => true, 'prior' => 5];
}

// Learn relevance only from resolved, non-overlapping outcomes. Constant signals
// carry no distance; duplicate signals share a weight instead of voting twice.
function spFeatureWeights(array $past): array
{
    $independent = [];
    $next = 0;
    foreach ($past as $sample) {
        if ($sample['time'] >= $next) {
            $independent[] = $sample;
            $next = $sample['end'];
        }
    }
    $weights = $signatures = [];
    for ($i = 0; $i < 50; $i++) {
        $n = $sx = $sy = $sxx = $syy = $sxy = 0;
        $signature = [];
        foreach ($independent as $s) {
            $x = $s['x'][$i];
            $signature[] = $x;
            if ($x === null) continue;
            $y = max(-1.0, min(1.0, $s['return']));
            $n++; $sx += $x; $sy += $y; $sxx += $x*$x; $syy += $y*$y; $sxy += $x*$y;
        }
        $vx = $n ? $sxx - $sx*$sx/$n : 0;
        $vy = $n ? $syy - $sy*$sy/$n : 0;
        $corr = $vx > 1e-8 && $vy > 1e-8 ? ($sxy-$sx*$sy/$n)/sqrt($vx*$vy) : 0;
        $weights[$i] = $vx > 1e-8 ? (.25 + 5*$corr*$corr*$n/($n+50)) : 0.0;
        $signatures[$i] = json_encode($signature);
    }
    $duplicates = array_count_values($signatures);
    foreach ($weights as $i => &$weight) $weight /= $duplicates[$signatures[$i]];
    unset($weight);
    // Keep the same five indicator blocks used by Indicadores and Super Previsão.
    for ($g = 0; $g < 5; $g++) {
        $sum = array_sum(array_slice($weights, $g*10, 10));
        for ($i = $g*10; $i < ($g+1)*10; $i++) $weights[$i] = $sum > 0 ? $weights[$i]/$sum : 0;
    }
    return $weights;
}

function spLegacyPredictionConfig(int $minutes, float $threshold): array
{
    $default = ['k' => 13, 'power' => 1.5, 'margin' => .03, 'halfLife' => 0, 'requireWinner' => false];
    if ($minutes === 5 && abs($threshold - .1) < .00001) {
        return ['k' => 9, 'power' => 1.25, 'margin' => .03, 'halfLife' => 4, 'requireWinner' => true];
    }
    if ($minutes === 5 && abs($threshold - .2) < .00001) {
        return ['k' => 15, 'power' => 1.0, 'margin' => .03, 'halfLife' => 4, 'requireWinner' => true];
    }
    if ($minutes === 5 && abs($threshold - .05) < .00001) {
        return ['k' => 7, 'power' => 2.0, 'margin' => .03, 'halfLife' => 8, 'requireWinner' => false];
    }
    if ($minutes === 30 && abs($threshold - .1) < .00001) {
        return ['k' => 7, 'power' => .75, 'margin' => .02, 'halfLife' => 0, 'requireWinner' => false];
    }
    if ($minutes === 15 && abs($threshold - .2) < .00001) {
        return ['k' => 7, 'power' => .75, 'margin' => .08, 'halfLife' => 0, 'requireWinner' => true];
    }
    if ($minutes === 30 && abs($threshold - .2) < .00001) {
        return ['k' => 13, 'power' => 3.0, 'margin' => .15, 'halfLife' => 24, 'requireWinner' => false];
    }
    if ($minutes === 60 && abs($threshold - .1) < .00001) {
        return ['k' => 7, 'power' => 1.75, 'margin' => 0, 'halfLife' => 0, 'requireWinner' => false];
    }
    if ($minutes === 60 && abs($threshold - .2) < .00001) {
        return ['k' => 7, 'power' => 1.75, 'margin' => 0, 'halfLife' => 0, 'requireWinner' => false];
    }
    return $default;
}

function spPredict(array $samples, array $query, ?array $config = null): ?array
{
    $config = $config ?: spPredictionConfig(5, .1);
    if (count(array_filter($query['x'], 'is_numeric')) < 40) {
        return null;
    }
    // Outcomes are admissible only after they have actually happened.
    $past = array_values(array_filter($samples, static function (array $s) use ($query): bool {
        return $s['end'] < $query['time'];
    }));
    $past = array_slice($past, -1500);
    if (count($past) < 40) {
        return null;
    }
    $featureWeights = !empty($config['adaptive']) ? spFeatureWeights($past) : array_fill(0, 50, 1.0);
    if (array_sum($featureWeights) <= 0) return null;
    $baseline = [0, 0, 0];
    $candidates = [];
    foreach ($past as $sample) {
        $baseline[$sample['label']]++;
        $sum = 0;
        $distanceWeight = 0;
        $common = 0;
        foreach ($query['x'] as $i => $value) {
            if ($value !== null && $sample['x'][$i] !== null) {
                $sum += $featureWeights[$i] * (($value - $sample['x'][$i]) / 4) ** 2;
                $distanceWeight += $featureWeights[$i];
                $common++;
            }
        }
        if ($common >= 40 && $distanceWeight > 0) {
            $squaredDistance = $sum / $distanceWeight;
            if (!empty($config['context'])) {
                $contextSum = 0; $contextCount = 0;
                foreach ($query['context'] ?? [] as $i => $v) {
                    $other = $sample['context'][$i] ?? null;
                    if ($v !== null && $other !== null) { $contextSum += (($v-$other)/4)**2; $contextCount++; }
                }
                if ($contextCount >= 3) $squaredDistance = .8*$squaredDistance + .2*$contextSum/$contextCount;
            }
            $sample['distance'] = sqrt($squaredDistance);
            $candidates[] = $sample;
        }
    }
    usort($candidates, static function (array $a, array $b): int {
        return ($a['distance'] <=> $b['distance']) ?: ($b['time'] <=> $a['time']);
    });
    $neighbors = [];
    foreach ($candidates as $sample) {
        $overlap = false;
        foreach ($neighbors as $chosen) {
            if ($sample['time'] < $chosen['end'] && $chosen['time'] < $sample['end']) {
                $overlap = true;
                break;
            }
        }
        if (!$overlap) {
            $neighbors[] = $sample;
        }
        if (count($neighbors) >= $config['k']) {
            break;
        }
    }
    if (count($neighbors) < 5) {
        return null;
    }
    $counts = [0, 0, 0];
    $weights = [0.0, 0.0, 0.0];
    $totalWeight = 0.0;
    $sumWeightSquared = $weightedReturn = $weightedSquaredReturn = 0.0;
    foreach ($neighbors as $s) {
        $counts[$s['label']]++;
        $weight = 1 / (($s['distance'] + .02) ** $config['power']);
        if ($config['halfLife'] > 0) {
            $ageHours = max(0.0, ($query['time'] - $s['end']) / 3600);
            $weight *= 0.5 ** ($ageHours / $config['halfLife']);
        }
        $weights[$s['label']] += $weight;
        $totalWeight += $weight;
        $sumWeightSquared += $weight*$weight;
        $weightedReturn += $weight*$s['return'];
        $weightedSquaredReturn += $weight*$s['return']*$s['return'];
    }
    if ($totalWeight <= 0) return null;
    $effective = $totalWeight*$totalWeight/max(1e-12, $sumWeightSquared);
    $prior = (float)($config['prior'] ?? 0);
    // Weighted frequencies from observed neighbors, not calibrated future probabilities.
    $probabilities = array_map(static function (float $v) use ($totalWeight): float {
        return $totalWeight > 0 ? $v / $totalWeight : 0.0;
    }, $weights);
    foreach ($probabilities as $i => &$probability) {
        $probability = ($effective*$probability + $prior*$baseline[$i]/count($past))/($effective+$prior);
    }
    unset($probability);
    $margin = $config['margin'];
    $label = 1;
    if ($probabilities[2] - max($probabilities[1], $probabilities[0]) >= $margin) {
        $label = 2;
    } elseif ($probabilities[0] - max($probabilities[1], $probabilities[2]) >= $margin) {
        $label = 0;
    } elseif (!$config['requireWinner'] && abs($probabilities[2] - $probabilities[0]) >= $margin) {
        $label = $probabilities[2] > $probabilities[0] ? 2 : 0;
    }
    $returns = array_column($neighbors, 'return');
    sort($returns);
    $average = $weightedReturn/$totalWeight;
    return ['label' => $label, 'frequencies' => $probabilities, 'count' => count($neighbors),
        'effective' => $effective, 'featureWeights' => $featureWeights,
        'standardError' => sqrt(max(0, $weightedSquaredReturn/$totalWeight-$average*$average)/max(1,$effective)),
        'training' => count($past), 'baseline' => (int)array_search(max($baseline), $baseline, true),
        'average' => $average,
        'p10' => $returns[(int)floor((count($returns) - 1) * .1)],
        'p90' => $returns[(int)ceil((count($returns) - 1) * .9)],
        'similarity' => 1 - array_sum(array_column($neighbors, 'distance')) / count($neighbors),
        'neighbors' => $neighbors];
}

function spChoosePrediction(?array $adaptive, ?array $legacy, array $trials, int $before): ?array
{
    if (!$legacy) return $adaptive;
    if (!$adaptive) return $legacy;
    $resolved = array_values(array_filter($trials, static fn(array $t): bool => $t['end'] < $before));
    $resolved = array_slice($resolved, -60);
    $loss = ['adaptive'=>0.0, 'legacy'=>0.0];
    foreach ($resolved as $t) {
        foreach ($loss as $name=>$_) {
            $p = $t[$name];
            $loss[$name] += (int)($p['label'] !== $t['actual']);
            foreach ($p['frequencies'] as $label=>$frequency) {
                $loss[$name] += .1*($frequency-(int)($label===$t['actual']))**2;
            }
        }
    }
    // Promote the challenger only on earlier outcomes, with a small margin.
    // This prevents replacing an established horizon with an untested model.
    $winner = count($resolved)>=12 && $loss['adaptive']+.02*count($resolved)<$loss['legacy'] ? 'adaptive' : 'legacy';
    $chosen = $winner==='adaptive'?$adaptive:$legacy;
    $chosen['model'] = $winner;
    $chosen['selectionTests'] = count($resolved);
    return $chosen;
}

function spAnalyze(array $prepared, int $minutes, float $threshold, ?array $config = null): array
{
    if (!$prepared) {
        return ['rows' => [], 'prediction' => null, 'tests' => [], 'metrics' => null, 'samples' => 0];
    }
    $latest = $prepared[count($prepared) - 1];
    // A 1h indicator score must not be compared with a 5m score or a renamed schema.
    $rows = array_values(array_filter($prepared, static function (array $r) use ($latest): bool {
        return $r['interval'] === $latest['interval'] && $r['names'] === $latest['names'];
    }));
    $samples = spSamples($rows, $minutes, $threshold);
    $predictionConfig = $config ?? spPredictionConfig($minutes, $threshold);
    $policy = array_replace(tradePolicy(), ['minutes' => $minutes]);
    $tests = [];
    $trials = [];
    $nextTest = 0;
    $testFrom = $latest['time'] - 120 * ($minutes * 60 + 120);
    foreach ($samples as $sample) {
        if ($sample['time'] < $nextTest || $sample['time'] < $testFrom) {
            continue;
        }
        $prediction = spPredict($samples, $sample, $predictionConfig);
        if ($config === null) {
            $legacy = spPredict($samples, $sample, spLegacyPredictionConfig($minutes, $threshold));
            $adaptive = $prediction;
            $prediction = spChoosePrediction($adaptive, $legacy, $trials, $sample['time']);
            if ($adaptive && $legacy) {
                $trials[] = ['end'=>$sample['end'], 'actual'=>$sample['label'],
                    'adaptive'=>['label'=>$adaptive['label'],'frequencies'=>$adaptive['frequencies']],
                    'legacy'=>['label'=>$legacy['label'],'frequencies'=>$legacy['frequencies']]];
            }
        }
        if ($prediction === null) {
            continue;
        }
        $candidate = tradeCandidate($prediction, $policy);
        $evidence = tradeEvidence($tests, $sample['time'], $policy);
        $decision = tradeDecision($prediction, $evidence, true, $policy);
        $outcome = tradeReplayOutcome($rows, $sample, $candidate['label'], $policy);
        $tests[] = ['time' => $sample['time'], 'end' => $sample['end'],
            'predicted' => $prediction['label'], 'actual' => $sample['label'],
            'model' => $prediction['model'] ?? 'fixed',
            'return' => $sample['return'], 'baseline' => $prediction['baseline'],
            'candidate' => $candidate['label'], 'candidateNetPct' => $outcome['net'],
            'tradeLabel' => $decision['label'], 'tradeReason' => $decision['reason'],
            'tradeEnd' => $outcome['end'], 'tradeBtcReturn' => $outcome['return'], 'exitReason' => $outcome['reason'],
            'frequencies' => $prediction['frequencies']];
        $nextTest = $sample['end'];
    }
    $metrics = null;
    if ($tests) {
        $correct = $baselineCorrect = $directional = $directionCorrect = 0;
        foreach ($tests as $test) {
            $correct += (int)($test['predicted'] === $test['actual']);
            $baselineCorrect += (int)($test['baseline'] === $test['actual']);
            if ($test['predicted'] !== 1) {
                $directional++;
                $directionCorrect += (int)($test['predicted'] === $test['actual']);
            }
        }
        $n = count($tests);
        $p = $correct / $n;
        $z = 1.96;
        $center = ($p + $z * $z / (2 * $n)) / (1 + $z * $z / $n);
        $radius = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / (1 + $z * $z / $n);
        $metrics = ['count' => $n, 'correct' => $correct, 'accuracy' => $p,
            'baseline' => $baselineCorrect / $n, 'low' => max(0, $center - $radius),
            'high' => min(1, $center + $radius), 'directional' => $directional,
            'directionAccuracy' => $directional ? $directionCorrect / $directional : null];
    }
    $current = spPredict($samples, $latest, $predictionConfig);
    if ($config === null) $current = spChoosePrediction($current,
        spPredict($samples, $latest, spLegacyPredictionConfig($minutes, $threshold)), $trials, $latest['time']);
    return ['rows' => $rows, 'prediction' => $current,
        'evidence' => tradeEvidence($tests, $latest['time'], $policy),
        'tests' => $tests, 'metrics' => $metrics, 'samples' => count($samples)];
}
