<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/indicador_settings.php';
require_once __DIR__ . '/previsao_dados.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');

const CRON_TRADE_INITIAL_BALANCE = 100.0;
const CRON_TRADE_HORIZON = 5;
const CRON_TRADE_THRESHOLD = 0.1;

function cronEnsureTradeSchema(PDO $pdo): void
{
    $schema = file_get_contents(__DIR__ . '/criar_tabela_trade_simulado.sql');
    if ($schema === false) {
        throw new RuntimeException('Arquivo criar_tabela_trade_simulado.sql nao encontrado.');
    }
    $pdo->exec($schema);
    $columns = [
        'is_live' => 'ALTER TABLE trade_simulado ADD COLUMN is_live TINYINT(1) NOT NULL DEFAULT 0',
        'event_type' => "ALTER TABLE trade_simulado ADD COLUMN event_type VARCHAR(24) NULL",
        'position_side' => "ALTER TABLE trade_simulado ADD COLUMN position_side VARCHAR(8) NULL",
        'position_qty' => 'ALTER TABLE trade_simulado ADD COLUMN position_qty DECIMAL(24, 12) NOT NULL DEFAULT 0',
        'position_entry_price' => 'ALTER TABLE trade_simulado ADD COLUMN position_entry_price DECIMAL(20, 8) NULL',
        'cash_balance' => 'ALTER TABLE trade_simulado ADD COLUMN cash_balance DECIMAL(20, 8) NOT NULL DEFAULT 100.00000000',
        'equity_after' => 'ALTER TABLE trade_simulado ADD COLUMN equity_after DECIMAL(20, 8) NOT NULL DEFAULT 100.00000000',
        'unrealized_pnl_usd' => 'ALTER TABLE trade_simulado ADD COLUMN unrealized_pnl_usd DECIMAL(20, 8) NOT NULL DEFAULT 0',
    ];
    $existing = [];
    foreach ($pdo->query('SHOW COLUMNS FROM trade_simulado') as $row) {
        $existing[(string)$row['Field']] = true;
    }
    foreach ($columns as $name => $sql) {
        if (!isset($existing[$name])) {
            $pdo->exec($sql);
        }
    }
}

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

function cronRunId(PDO $pdo): string
{
    cronEnsureTradeSchema($pdo);
    $row = $pdo->query('SELECT run_id FROM trade_simulado WHERE is_live = 1 ORDER BY id DESC LIMIT 1')->fetch();
    return $row ? (string)$row['run_id'] : 'live_' . date('Ymd');
}

function cronTradeState(PDO $pdo, string $runId): array
{
    $stmt = $pdo->prepare('SELECT * FROM trade_simulado WHERE is_live = 1 AND run_id = :run_id ORDER BY id DESC LIMIT 1');
    $stmt->execute([':run_id' => $runId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['side' => 'FLAT', 'entry' => null, 'qty' => 0.0, 'cash' => CRON_TRADE_INITIAL_BALANCE];
    }
    return ['side' => (string)($row['position_side'] ?: 'FLAT'),
        'entry' => $row['position_entry_price'] !== null ? (float)$row['position_entry_price'] : null,
        'qty' => (float)$row['position_qty'], 'cash' => (float)$row['cash_balance']];
}

function cronUnrealized(array $state, float $price): float
{
    if ($state['side'] === 'LONG' && $state['entry']) {
        return ($price - $state['entry']) * $state['qty'];
    }
    if ($state['side'] === 'SHORT' && $state['entry']) {
        return ($state['entry'] - $price) * $state['qty'];
    }
    return 0.0;
}

function cronInsertTrade(PDO $pdo, string $runId, array $event): void
{
    $stmt = $pdo->prepare('INSERT INTO trade_simulado (run_id, is_live, event_type, strategy_minutes, threshold_pct,
        initial_balance, balance_before, balance_after, entry_time, exit_time, side, entry_price, exit_price,
        btc_return_pct, trade_return_pct, pnl_usd, predicted_label, actual_label, was_correct, neighbors_count,
        similarity, probability_up, probability_flat, probability_down, notes, position_side, position_qty,
        position_entry_price, cash_balance, equity_after, unrealized_pnl_usd)
        VALUES (:run_id, 1, :event_type, :strategy_minutes, :threshold_pct, :initial_balance, :balance_before,
        :balance_after, :entry_time, :exit_time, :side, :entry_price, :exit_price, :btc_return_pct,
        :trade_return_pct, :pnl_usd, :predicted_label, 1, :was_correct, :neighbors_count, :similarity,
        :probability_up, :probability_flat, :probability_down, :notes, :position_side, :position_qty,
        :position_entry_price, :cash_balance, :equity_after, :unrealized_pnl_usd)');
    $stmt->execute($event + [':run_id' => $runId, ':strategy_minutes' => CRON_TRADE_HORIZON,
        ':threshold_pct' => CRON_TRADE_THRESHOLD, ':initial_balance' => CRON_TRADE_INITIAL_BALANCE]);
}

function cronTrade(PDO $pdo): array
{
    $runId = cronRunId($pdo);
    $last = $pdo->prepare('SELECT created_at FROM trade_simulado WHERE is_live = 1 AND run_id = :run_id ORDER BY id DESC LIMIT 1');
    $last->execute([':run_id' => $runId]);
    $lastRow = $last->fetch();
    if ($lastRow && time() - strtotime((string)$lastRow['created_at']) < 60) {
        return ['ok' => true, 'executed' => false, 'reason' => 'interval_lock'];
    }
    $data = spData(CRON_TRADE_HORIZON, CRON_TRADE_THRESHOLD);
    $prediction = $data['prediction'] ?? null;
    $latest = $data['latest'] ?? null;
    if (!$prediction || !$latest) {
        return ['ok' => false, 'executed' => false, 'reason' => 'no_prediction'];
    }
    $price = (float)$latest['btc'];
    $time = date('Y-m-d H:i:s', (int)$latest['time']);
    $tradeLabel = (int)$prediction['label'];
    if ($tradeLabel === 1) {
        $tradeLabel = $prediction['frequencies'][2] >= $prediction['frequencies'][0] ? 2 : 0;
    }
    $desired = $tradeLabel === 2 ? 'LONG' : 'SHORT';
    $state = cronTradeState($pdo, $runId);
    if ($state['side'] === $desired) {
        return ['ok' => true, 'executed' => false, 'reason' => 'same_position', 'position' => $desired];
    }
    $events = 0;
    if ($state['side'] !== 'FLAT') {
        $pnl = cronUnrealized($state, $price);
        $cash = max(0.0, $state['cash'] + $pnl);
        $btcReturn = ($price / (float)$state['entry'] - 1) * 100;
        $tradeReturn = $state['side'] === 'LONG' ? $btcReturn : -$btcReturn;
        cronInsertTrade($pdo, $runId, [
            ':event_type' => 'CLOSE_' . $state['side'], ':balance_before' => $state['cash'], ':balance_after' => $cash,
            ':entry_time' => $time, ':exit_time' => $time, ':side' => $state['side'], ':entry_price' => $state['entry'],
            ':exit_price' => $price, ':btc_return_pct' => $btcReturn, ':trade_return_pct' => $tradeReturn,
            ':pnl_usd' => $pnl, ':predicted_label' => $tradeLabel, ':was_correct' => $pnl > 0 ? 1 : 0,
            ':neighbors_count' => (int)$prediction['count'], ':similarity' => (float)$prediction['similarity'],
            ':probability_up' => (float)$prediction['frequencies'][2], ':probability_flat' => (float)$prediction['frequencies'][1],
            ':probability_down' => (float)$prediction['frequencies'][0], ':notes' => 'Fechamento pelo cron central.',
            ':position_side' => 'FLAT', ':position_qty' => 0, ':position_entry_price' => null, ':cash_balance' => $cash,
            ':equity_after' => $cash, ':unrealized_pnl_usd' => 0,
        ]);
        $state = ['side' => 'FLAT', 'entry' => null, 'qty' => 0.0, 'cash' => $cash];
        $events++;
    }
    $qty = $state['cash'] / $price;
    cronInsertTrade($pdo, $runId, [
        ':event_type' => 'OPEN_' . $desired, ':balance_before' => $state['cash'], ':balance_after' => $state['cash'],
        ':entry_time' => $time, ':exit_time' => $time, ':side' => $desired, ':entry_price' => $price,
        ':exit_price' => $price, ':btc_return_pct' => 0, ':trade_return_pct' => 0, ':pnl_usd' => 0,
        ':predicted_label' => $tradeLabel, ':was_correct' => 0, ':neighbors_count' => (int)$prediction['count'],
        ':similarity' => (float)$prediction['similarity'], ':probability_up' => (float)$prediction['frequencies'][2],
        ':probability_flat' => (float)$prediction['frequencies'][1], ':probability_down' => (float)$prediction['frequencies'][0],
        ':notes' => 'Abertura pelo cron central.', ':position_side' => $desired, ':position_qty' => $qty,
        ':position_entry_price' => $price, ':cash_balance' => $state['cash'], ':equity_after' => $state['cash'],
        ':unrealized_pnl_usd' => 0,
    ]);
    return ['ok' => true, 'executed' => true, 'events' => $events + 1, 'position' => $desired, 'price' => $price];
}

$lock = fopen(__DIR__ . '/cron_trade.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['ok' => true, 'locked' => true, 'message' => 'Cron ja esta em execucao.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = ['ok' => true, 'generated' => date('c'), 'tasks' => []];
try {
    $pdo = appPdo();
    $result['tasks']['coleta'] = cronCollectIndicators($pdo);
    $result['tasks']['super_previsao'] = spData(CRON_TRADE_HORIZON, CRON_TRADE_THRESHOLD);
    $result['tasks']['trade_simulado'] = cronTrade($pdo);
} catch (Throwable $error) {
    $result['ok'] = false;
    $result['error'] = $error->getMessage();
}

flock($lock, LOCK_UN);
fclose($lock);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
