<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/previsao_motor.php';
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "OK: $message\n";
}
$seed = json_decode((string)file_get_contents(dirname(__DIR__) . '/data/super_previsao_base.json'), true, 512, JSON_THROW_ON_ERROR);
$rows = spPrepare($seed['rows']);
check(count($rows) === 447, 'All 447 supplied SQL rows parsed');
check(count($rows[0]['names']) === 50 && count($rows[0]['x']) === 50, 'Names and scores preserved');
$samples = spSamples($rows, 15, .1);
foreach ($samples as $s) {
    check($s['end'] >= $s['time'] + 900 && $s['end'] <= $s['time'] + 1020, 'Elapsed-time endpoint valid');
}
$query = $rows[300];
$prediction = spPredict($samples, $query);
$pastOnly = array_values(array_filter($samples, static function (array $s) use ($query): bool { return $s['end'] < $query['time']; }));
check($prediction === spPredict($pastOnly, $query), 'Unobserved future outcomes cannot change prediction');
$poisoned = $samples;
foreach ($poisoned as &$s) {
    if ($s['end'] >= $query['time']) {
        $s['label'] = 2; $s['return'] = 500;
    }
}
unset($s);
check($prediction === spPredict($poisoned, $query), 'Changing future labels cannot leak into prediction');
check($prediction !== null && abs(array_sum($prediction['frequencies']) - 1) < 1e-9, 'Observed frequencies total 100 percent');
foreach ($prediction['neighbors'] as $i => $a) {
    check($a['end'] < $query['time'], 'Every analog was resolved before prediction');
    foreach (array_slice($prediction['neighbors'], $i + 1) as $b) {
        if ($a['time'] < $b['end'] && $b['time'] < $a['end']) {
            throw new RuntimeException('Overlapping analog outcomes');
        }
    }
}
check(spPredict([], $query) === null, 'Empty training set abstains');
$query['x'] = array_fill(0, 50, null);
check(spPredict($samples, $query) === null, 'Missing indicators abstain');
check(spAnalyze([], 15, .1)['prediction'] === null, 'Empty history is supported');
check(spLabel(.1, .1) === 1 && spLabel(-.1, .1) === 1 && spLabel(.101, .1) === 2 && spLabel(-.101, .1) === 0, 'Threshold boundaries are symmetric');
foreach ([5, 15, 30, 60] as $minutes) {
    $analysis = spAnalyze($rows, $minutes, .1);
    $previousEnd = 0;
    foreach ($analysis['tests'] as $test) {
        if ($test['time'] < $previousEnd) throw new RuntimeException('Overlapping validation periods');
        $previousEnd = $test['end'];
    }
    check(true, "Non-overlapping validation for $minutes minutes");
}
$mixed = $rows;
$mixed[100]['interval'] = '5m';
check(count(spAnalyze($mixed, 15, .1)['rows']) === count($rows) - 1, 'Different candle intervals are excluded');
$mixed[101]['names'][0] = 'Changed indicator';
check(count(spAnalyze($mixed, 15, .1)['rows']) === count($rows) - 2, 'Different indicator schemas are excluded');
$gapRows = array_values(array_filter($rows, static function (array $r) use ($rows): bool {
    return $r['time'] < $rows[200]['time'] || $r['time'] > $rows[200]['time'] + 7200;
}));
foreach (spSamples($gapRows, 15, .1) as $s) {
    if ($s['end'] - $s['time'] > 1020) throw new RuntimeException('Collection gap accepted');
}
check(true, 'Large collection gaps do not become target outcomes');
