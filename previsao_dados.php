<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/previsao_motor.php';

function spData(int $minutes, float $threshold): array
{
    $seedPath = __DIR__ . '/data/super_previsao_base.json';
    $seed = is_file($seedPath) ? json_decode((string)file_get_contents($seedPath), true) : [];
    $saved = is_array($seed) ? ($seed['rows'] ?? []) : [];
    $live = [];
    $database = 'empty';
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 3]);
        $live = $pdo->query('SELECT * FROM indicador_historico ORDER BY created_at DESC, id DESC LIMIT 3000')->fetchAll();
        $database = $live ? 'ok' : 'empty';
    } catch (Throwable $error) {
        $database = 'unavailable';
    }
    $merged = [];
    foreach (array_merge($saved, array_reverse($live)) as $row) {
        $merged[$row['created_at'] . '|' . $row['interval_used']] = $row;
    }
    $prepared = spPrepare(array_values($merged));
    $prepared = array_slice($prepared, -3000);
    $analysis = spAnalyze($prepared, $minutes, $threshold);
    $rows = $analysis['rows'];
    $latest = $rows ? $rows[count($rows) - 1] : null;
    $prediction = $analysis['prediction'];
    $metrics = $analysis['metrics'];
    $age = $latest ? time() - $latest['time'] : null;
    $fresh = $age !== null && $age >= -120 && $age <= 180 && $database === 'ok';
    $hours = $latest ? ($latest['time'] - $rows[0]['time']) / 3600 : 0;
    $validated = $metrics && $metrics['count'] >= 30 && $hours >= 24
        && $metrics['low'] > $metrics['baseline'];
    $ranked = $prediction ? $prediction['frequencies'] : [];
    rsort($ranked);
    $clear = $prediction && $prediction['label'] !== 1 && $prediction['count'] >= 10
        && $ranked[0] >= .6 && $ranked[0] - $ranked[1] >= .15;
    $status = !$latest ? 'empty' : (!$fresh ? 'historical' : ($validated && $clear ? 'signal' : 'experimental'));
    $chartEnd = $fresh ? time() : ($latest['time'] ?? time());
    $chart = [];
    foreach ($rows as $r) {
        if ($r['time'] >= $chartEnd - 86400 && $r['time'] <= $chartEnd) {
            $chart[] = ['time' => $r['time'], 'btc' => $r['btc'], 'eth' => $r['eth']];
        }
    }
    $variable = 0;
    for ($i = 0; $i < 50; $i++) {
        $values = [];
        foreach ($rows as $r) {
            if ($r['x'][$i] !== null) {
                $values[(string)$r['x'][$i]] = true;
            }
        }
        $variable += (int)(count($values) > 1);
    }
    return ['minutes' => $minutes, 'threshold' => $threshold, 'generated' => time(),
        'timezone' => date_default_timezone_get(), 'source' => $database === 'ok' ? 'Banco + base SQL' : 'Base SQL importada',
        'database' => $database, 'status' => $status, 'fresh' => $fresh,
        'validated' => (bool)$validated, 'records' => count($rows), 'excluded' => count($prepared) - count($rows),
        'hours' => $hours, 'variable' => $variable, 'first' => $rows ? $rows[0]['time'] : null,
        'latest' => $latest, 'prediction' => $prediction, 'metrics' => $metrics,
        'samples' => $analysis['samples'], 'tests' => $analysis['tests'],
        'chart' => $chart, 'chartEnd' => $chartEnd];
}
