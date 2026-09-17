<?php
declare(strict_types=1);

const TRADE_MODEL_VERSION = 'adaptive-2';

function tradePolicy(): array
{
    // Simulation assumptions, not an exchange fee quote. Percent values.
    return ['feePct' => .10, 'slippagePct' => .02, 'allocation' => .25,
        'stopPct' => .35, 'takePct' => .70, 'minutes' => 5,
        'minEvidence' => 12, 'minEffective' => 8, 'minEdgePct' => .03];
}

function tradeRoundTripCost(?array $policy = null): float
{
    $p = $policy ?? tradePolicy();
    return 2 * ($p['feePct'] + $p['slippagePct']);
}

function tradeCandidate(?array $prediction, ?array $policy = null): array
{
    $p = $policy ?? tradePolicy();
    $result = ['label' => 1, 'reason' => 'Sem cenários suficientes.', 'expectedNetPct' => null];
    if (!$prediction) return $result;
    $label = (int)$prediction['label'];
    if ($label === 1) return array_replace($result, ['reason' => 'Mercado lateral ou indicadores sem direção clara.']);
    $f = $prediction['frequencies'];
    if (($prediction['effective'] ?? 0) < $p['minEffective'] || $f[$label] < .50
        || $f[$label] - max($f[1], $f[2-$label]) < .12) {
        return array_replace($result, ['reason' => 'Poucos cenários independentes ou sinal fraco.']);
    }
    $expected = $prediction['average'] * ($label === 2 ? 1 : -1) - tradeRoundTripCost($p);
    $result['expectedNetPct'] = $expected;
    if ($expected - ($prediction['standardError'] ?? 0) <= $p['minEdgePct']) {
        return array_replace($result, ['reason' => 'Movimento estimado não cobre custos e margem de incerteza.']);
    }
    return array_replace($result, ['label' => $label, 'reason' => 'Cenários indicam movimento superior aos custos.']);
}

function tradeEvidence(array $tests, int $before, ?array $policy = null): array
{
    $p = $policy ?? tradePolicy();
    $resolved = array_values(array_filter($tests, static fn(array $t): bool =>
        $t['end'] < $before && ($t['candidate'] ?? 1) !== 1));
    $resolved = array_slice($resolved, -100);
    $returns = array_map(static fn(array $t): float => (float)$t['candidateNetPct'], $resolved);
    $n = count($returns);
    $mean = $n ? array_sum($returns)/$n : 0;
    $variance = 0; $wins = 0; $gain = $loss = 0.0;
    foreach ($returns as $r) { $variance += ($r-$mean)**2; $wins += (int)($r>0); $gain+=max(0,$r); $loss+=max(0,-$r); }
    $lower = $n > 1 ? $mean - 1.645*sqrt($variance/($n-1)/$n) : null;
    return ['count' => $n, 'meanNetPct' => $mean, 'lowerNetPct' => $lower,
        'winRate' => $n ? $wins/$n : null, 'profitFactor' => $loss > 0 ? $gain/$loss : null,
        'approved' => $n >= $p['minEvidence'] && $lower > 0];
}

function tradeDecision(?array $prediction, array $evidence, bool $fresh, ?array $policy = null): array
{
    $candidate = tradeCandidate($prediction, $policy);
    if (!$fresh) return array_replace($candidate, ['label' => 1, 'reason' => 'Aguardando dados recentes do banco.']);
    if ($candidate['label'] !== 1 && empty($evidence['approved'])) {
        return array_replace($candidate, ['label' => 1, 'reason' => 'Aguardando validação temporal positiva após custos.']);
    }
    return $candidate;
}

// Both the historical replay and live simulator use these same exit rules.
function tradeExitReason(array $state, float $price, int $time, ?array $policy = null): ?string
{
    $p = $policy ?? tradePolicy();
    if ($state['side'] === 'FLAT' || empty($state['entry'])) return null;
    $r = ($price/$state['entry']-1)*100*($state['side']==='LONG'?1:-1);
    if ($r <= -$p['stopPct']) return 'Limite de perda';
    if ($r >= $p['takePct']) return 'Realização de lucro';
    if ($time >= $state['entry_ts'] + $p['minutes']*60) return 'Horizonte concluído';
    return null;
}

function tradeReplayOutcome(array $rows, array $sample, int $label, ?array $policy = null): array
{
    $p = $policy ?? tradePolicy();
    $state = ['side' => $label === 2 ? 'LONG' : 'SHORT', 'entry' => $sample['btc'], 'entry_ts' => $sample['time']];
    foreach ($rows as $row) {
        if ($row['time'] <= $sample['time']) continue;
        if ($row['time'] > $sample['end']) break;
        $reason = tradeExitReason($state, $row['btc'], $row['time'], $p);
        if ($reason !== null) {
            $r = ($row['btc']/$sample['btc']-1)*100;
            return ['end' => $row['time'], 'return' => $r,
                'net' => $r*($label===2?1:-1)-tradeRoundTripCost($p), 'reason' => $reason];
        }
    }
    return ['end' => $sample['end'], 'return' => $sample['return'],
        'net' => $sample['return']*($label===2?1:-1)-tradeRoundTripCost($p), 'reason' => 'Horizonte concluído'];
}
