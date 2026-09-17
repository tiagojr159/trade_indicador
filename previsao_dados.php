<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/previsao_motor.php';

function spData(int $minutes, float $threshold): array
{
    static $memo = [];
    $key = $minutes . ':' . $threshold;
    if (isset($memo[$key])) return $memo[$key];
    $seedPath = __DIR__ . '/data/super_previsao_base.json';
    $seed = is_file($seedPath) ? json_decode((string)file_get_contents($seedPath), true) : [];
    $saved = is_array($seed) ? ($seed['rows'] ?? []) : [];
    $live = [];
    $database = 'empty';
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 3]);
        $live = $pdo->query('SELECT * FROM indicador_historico ORDER BY created_at DESC, id DESC LIMIT 6000')->fetchAll();
        $database = $live ? 'ok' : 'empty';
    } catch (Throwable $error) {
        $database = 'unavailable';
    }
    $merged = [];
    foreach ($live ? array_reverse($live) : $saved as $row) {
        $merged[$row['created_at'] . '|' . $row['interval_used']] = $row;
    }
    $prepared = spPrepare(array_values($merged));
    $prepared = array_slice($prepared, -6000);
    // Cache by data content and engine code. A new/edited quote invalidates it;
    // freshness is always evaluated below, including on cache hits.
    $fingerprint = hash('sha256', serialize($prepared).$key.hash_file('sha256',__DIR__.'/previsao_motor.php')
        .hash_file('sha256',__DIR__.'/trade_policy.php'));
    $cachePath = __DIR__.'/data/previsao_cache_'.$minutes.'_'.(int)round($threshold*10000).'.json';
    $cached = null;
    if (is_file($cachePath) && ($handle = fopen($cachePath,'r'))) {
        flock($handle,LOCK_SH);
        $cached = json_decode((string)stream_get_contents($handle),true);
        flock($handle,LOCK_UN); fclose($handle);
    }
    if (is_array($cached) && ($cached['key'] ?? '') === $fingerprint) {
        $analysis = $cached['analysis'];
    } else {
        $analysis = spAnalyze($prepared, $minutes, $threshold);
        file_put_contents($cachePath,json_encode(['key'=>$fingerprint,'analysis'=>$analysis],JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX);
    }
    $rows = $analysis['rows'];
    $latest = $rows ? $rows[count($rows) - 1] : null;
    $prediction = $analysis['prediction'];
    $metrics = $analysis['metrics'];
    $age = $latest ? time() - $latest['time'] : null;
    $fresh = $age !== null && $age >= -5 && $age <= 180 && $database === 'ok';
    $hours = $latest ? ($latest['time'] - $rows[0]['time']) / 3600 : 0;
    $evidence = $analysis['evidence'] ?? tradeEvidence([], time());
    $validated = $evidence['approved'];
    $decision = tradeDecision($prediction, $evidence, $fresh);
    $clear = $decision['label'] !== 1;
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
    return $memo[$key] = ['minutes' => $minutes, 'threshold' => $threshold, 'generated' => time(),
        'modelVersion' => TRADE_MODEL_VERSION, 'decision' => $decision, 'evidence' => $evidence, 'policy' => tradePolicy(),
        'timezone' => date_default_timezone_get(), 'source' => $database === 'ok' ? 'Banco em tempo real' : 'Base SQL importada',
        'database' => $database, 'status' => $status, 'fresh' => $fresh,
        'validated' => (bool)$validated, 'records' => count($rows), 'excluded' => count($prepared) - count($rows),
        'hours' => $hours, 'variable' => $variable, 'first' => $rows ? $rows[0]['time'] : null,
        'latest' => $latest, 'prediction' => $prediction, 'metrics' => $metrics,
        'samples' => $analysis['samples'], 'tests' => $analysis['tests'],
        'chart' => $chart, 'chartEnd' => $chartEnd];
}
