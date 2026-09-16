<?php
declare(strict_types=1);

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
        $samples[] = ['time' => $row['time'], 'end' => $rows[$j]['time'],
            'x' => $row['x'], 'return' => $return, 'label' => spLabel($return, $threshold)];
    }
    return $samples;
}

function spPredict(array $samples, array $query): ?array
{
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
    $baseline = [0, 0, 0];
    $candidates = [];
    foreach ($past as $sample) {
        $baseline[$sample['label']]++;
        $sum = 0;
        $common = 0;
        foreach ($query['x'] as $i => $value) {
            if ($value !== null && $sample['x'][$i] !== null) {
                $sum += (($value - $sample['x'][$i]) / 4) ** 2;
                $common++;
            }
        }
        if ($common >= 40) {
            $sample['distance'] = sqrt($sum / $common);
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
        if (count($neighbors) >= 13) {
            break;
        }
    }
    if (count($neighbors) < 5) {
        return null;
    }
    $counts = [0, 0, 0];
    $weights = [0.0, 0.0, 0.0];
    $totalWeight = 0.0;
    foreach ($neighbors as $s) {
        $counts[$s['label']]++;
        $weight = 1 / (($s['distance'] + .02) ** 1.5);
        $weights[$s['label']] += $weight;
        $totalWeight += $weight;
    }
    // Weighted frequencies from observed neighbors, not calibrated future probabilities.
    $probabilities = array_map(static function (float $v) use ($totalWeight): float {
        return $totalWeight > 0 ? $v / $totalWeight : 0.0;
    }, $weights);
    $margin = .03;
    $label = 1;
    if ($probabilities[2] - max($probabilities[1], $probabilities[0]) >= $margin) {
        $label = 2;
    } elseif ($probabilities[0] - max($probabilities[1], $probabilities[2]) >= $margin) {
        $label = 0;
    } elseif (abs($probabilities[2] - $probabilities[0]) >= $margin) {
        $label = $probabilities[2] > $probabilities[0] ? 2 : 0;
    }
    $returns = array_column($neighbors, 'return');
    sort($returns);
    return ['label' => $label, 'frequencies' => $probabilities, 'count' => count($neighbors),
        'training' => count($past), 'baseline' => (int)array_search(max($baseline), $baseline, true),
        'average' => array_sum($returns) / count($returns),
        'p10' => $returns[(int)floor((count($returns) - 1) * .1)],
        'p90' => $returns[(int)ceil((count($returns) - 1) * .9)],
        'similarity' => 1 - array_sum(array_column($neighbors, 'distance')) / count($neighbors),
        'neighbors' => $neighbors];
}

function spAnalyze(array $prepared, int $minutes, float $threshold): array
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
    $tests = [];
    $nextTest = 0;
    $testFrom = $latest['time'] - 120 * ($minutes * 60 + 120);
    foreach ($samples as $sample) {
        if ($sample['time'] < $nextTest || $sample['time'] < $testFrom) {
            continue;
        }
        $prediction = spPredict($samples, $sample);
        if ($prediction === null) {
            continue;
        }
        $tests[] = ['time' => $sample['time'], 'end' => $sample['end'],
            'predicted' => $prediction['label'], 'actual' => $sample['label'],
            'return' => $sample['return'], 'baseline' => $prediction['baseline'],
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
    return ['rows' => $rows, 'prediction' => spPredict($samples, $latest),
        'tests' => $tests, 'metrics' => $metrics, 'samples' => count($samples)];
}
