<?php
declare(strict_types=1);
require_once __DIR__ . '/indicador_settings.php';
require_once __DIR__ . '/trade_exec.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');
function cronCollectIndicators(PDO $pdo): array
{
    $schema = file_get_contents(__DIR__ . '/criar_tabela_indicadores.sql');
    if ($schema === false) {
        throw new RuntimeException('Arquivo criar_tabela_indicadores.sql nao encontrado.');
    }
    $pdo->exec($schema);

    $saveIntervalMinutes = indicadorSaveIntervalMinutes();
    $lastRow = $pdo->query('SELECT id, created_at FROM indicador_historico ORDER BY created_at DESC LIMIT 1')->fetch();
    $lastSavedAt = $lastRow ? strtotime((string)$lastRow['created_at']) : false;
    $nextSaveAt = $lastSavedAt === false ? 0 : $lastSavedAt + $saveIntervalMinutes * 60;
    if ($lastSavedAt !== false && time() < $nextSaveAt) {
        return ['ok' => true, 'saved' => false, 'reason' => 'interval_lock',
            'remaining_seconds' => max(0, $nextSaveAt - time())];
    }

    $_GET['interval'] = $_GET['interval'] ?? '1h';
    ob_start();
    require __DIR__ . '/indicadores.php';
    ob_end_clean();

    if (!isset($inds) || !is_array($inds)) {
        throw new RuntimeException('Nao foi possivel calcular os indicadores.');
    }
    $ethTicker = function_exists('httpJson') ? httpJson('https://api.binance.com/api/v3/ticker/24hr?symbol=ETHUSDT') : [];
    $ethPrice = isset($ethTicker['lastPrice']) ? (float)$ethTicker['lastPrice'] : null;
    $columns = ['interval_used', 'btc_price', 'eth_price'];
    $placeholders = [':interval_used', ':btc_price', ':eth_price'];
    $params = [':interval_used' => $interval ?? '1h', ':btc_price' => $currentPrice ?? null, ':eth_price' => $ethPrice];
    for ($i = 1; $i <= 50; $i++) {
        $column = 'indicator_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $columns[] = $column;
        $placeholders[] = ':' . $column;
        $params[':' . $column] = isset($inds[$i - 1]['score']) ? (float)$inds[$i - 1]['score'] : null;
    }
    $columns[] = 'indicator_names';
    $placeholders[] = ':indicator_names';
    $params[':indicator_names'] = json_encode(array_map(static fn(array $it): string => (string)$it['name'], array_slice($inds, 0, 50)), JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare('INSERT INTO indicador_historico (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
    $stmt->execute($params);
    return ['ok' => true, 'saved' => true, 'id' => (int)$pdo->lastInsertId(),
        'btc_price' => $currentPrice ?? null, 'eth_price' => $ethPrice, 'indicators' => min(50, count($inds))];
}

$lock = fopen(__DIR__ . '/cron_trade.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    if ($lock) fclose($lock);
    echo json_encode(['ok'=>true,'locked'=>true]);
    exit;
}
$result = ['ok'=>true,'generated'=>date('c'),'tasks'=>[]];
try {
    $pdo = tsPdo();
    // A collection failure must not prevent monitoring the latest usable quote.
    try {
        $collectionLock = fopen(__DIR__.'/indicador_coleta.lock','c');
        if (!$collectionLock || !flock($collectionLock, LOCK_EX | LOCK_NB)) {
            $result['tasks']['coleta'] = ['ok'=>true,'locked'=>true];
        } else {
            try { $result['tasks']['coleta'] = cronCollectIndicators($pdo); }
            finally { flock($collectionLock, LOCK_UN); }
        }
        if ($collectionLock) fclose($collectionLock);
    } catch (Throwable $error) { $result['tasks']['coleta'] = ['ok'=>false,'error'=>$error->getMessage()]; }
    $data = spData(LIVE_HORIZON_MINUTES,LIVE_THRESHOLD,true);
    $result['tasks']['super_previsao'] = $data;
    unset($result['tasks']['super_previsao']['history']);
    $result['tasks']['trade_simulado'] = tsExecute($pdo,60,$data);
} catch (Throwable $error) {
    $result['ok']=false;
    $result['error']=$error->getMessage();
} finally { flock($lock,LOCK_UN); fclose($lock); }
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
