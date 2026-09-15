<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (PHP_SAPI === 'cli' && isset($argv[1])) {
    $_GET['interval'] = $argv[1];
}
$_GET['interval'] = $_GET['interval'] ?? '1h';

ob_start();
require __DIR__ . '/indicadores.php';
ob_end_clean();

if (!isset($inds) || !is_array($inds)) {
    http_response_code(500);
    exit("Nao foi possivel calcular os indicadores.\n");
}

$pdo = appPdo();
$schema = file_get_contents(__DIR__ . '/criar_tabela_indicadores.sql');
if ($schema === false) {
    throw new RuntimeException('Arquivo criar_tabela_indicadores.sql nao encontrado.');
}
$pdo->exec($schema);

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

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'id' => (int)$pdo->lastInsertId(),
    'interval' => $interval ?? '1h',
    'btc_price' => $currentPrice ?? null,
    'eth_price' => $ethPrice,
    'indicators' => min(50, count($inds)),
    'created_at' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
