<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/indicador_settings.php';

if (PHP_SAPI === 'cli' && isset($argv[1])) {
    $_GET['interval'] = $argv[1];
}
$_GET['interval'] = $_GET['interval'] ?? '1h';

$pdo = appPdo();
$schema = file_get_contents(__DIR__ . '/criar_tabela_indicadores.sql');
if ($schema === false) {
    throw new RuntimeException('Arquivo criar_tabela_indicadores.sql nao encontrado.');
}
$pdo->exec($schema);

$lockHandle = fopen(__DIR__ . '/indicador_coleta.lock', 'c');
if ($lockHandle === false) {
    throw new RuntimeException('Nao foi possivel criar a trava da coleta.');
}
if (!flock($lockHandle, LOCK_EX)) {
    fclose($lockHandle);
    throw new RuntimeException('Nao foi possivel bloquear a coleta.');
}

$requestedSaveInterval = isset($_GET['save_interval_minutes']) ? (int)$_GET['save_interval_minutes'] : 0;
$saveIntervalMinutes = in_array($requestedSaveInterval, INDICADOR_ALLOWED_SAVE_INTERVALS, true)
    ? $requestedSaveInterval
    : indicadorSaveIntervalMinutes();
$saveIntervalSeconds = $saveIntervalMinutes * 60;
$lastStmt = $pdo->query('SELECT id, created_at FROM indicador_historico ORDER BY created_at DESC LIMIT 1');
$lastRow = $lastStmt->fetch();
$lastSavedAt = $lastRow ? strtotime((string)$lastRow['created_at']) : false;
$nextSaveAt = $lastSavedAt === false ? 0 : $lastSavedAt + $saveIntervalSeconds;

if ($lastSavedAt !== false && time() < $nextSaveAt) {
    $remainingSeconds = max(0, $nextSaveAt - time());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'saved' => false,
        'reason' => 'interval_lock',
        'message' => 'Registro ignorado: ainda nao passou o intervalo configurado.',
        'save_interval_minutes' => $saveIntervalMinutes,
        'last_saved_at' => date('c', $lastSavedAt),
        'next_save_at' => date('c', $nextSaveAt),
        'remaining_seconds' => $remainingSeconds,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit;
}

ob_start();
require __DIR__ . '/indicadores.php';
ob_end_clean();

if (!isset($inds) || !is_array($inds)) {
    http_response_code(500);
    exit("Nao foi possivel calcular os indicadores.\n");
}

$ethTicker = httpJson('https://api.binance.com/api/v3/ticker/24hr?symbol=ETHUSDT');
$ethPrice = isset($ethTicker['lastPrice']) ? (float)$ethTicker['lastPrice'] : null;

$columns = ['interval_used', 'btc_price', 'eth_price'];
$placeholders = [':interval_used', ':btc_price', ':eth_price'];
$params = [
    ':interval_used' => $interval ?? '1h',
    ':btc_price' => $currentPrice ?? null,
    ':eth_price' => $ethPrice,
];

for ($i = 1; $i <= 50; $i++) {
    $column = 'indicator_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
    $columns[] = $column;
    $placeholders[] = ':' . $column;
    $params[':' . $column] = isset($inds[$i - 1]['score']) ? (float)$inds[$i - 1]['score'] : null;
}

$columns[] = 'indicator_names';
$placeholders[] = ':indicator_names';
$params[':indicator_names'] = json_encode(array_map(static fn(array $it): string => (string)$it['name'], array_slice($inds, 0, 50)), JSON_UNESCAPED_UNICODE);

$sql = 'INSERT INTO indicador_historico (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$insertedId = (int)$pdo->lastInsertId();

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'saved' => true,
    'id' => $insertedId,
    'interval' => $interval ?? '1h',
    'save_interval_minutes' => $saveIntervalMinutes,
    'btc_price' => $currentPrice ?? null,
    'eth_price' => $ethPrice,
    'indicators' => min(50, count($inds)),
    'created_at' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
