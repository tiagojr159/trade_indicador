<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/previsao_dados.php';

const LIVE_INITIAL_BALANCE = 100.0;
const LIVE_THRESHOLD = 0.1;
const LIVE_HORIZON_MINUTES = 5;

function tsPdo(): PDO
{
    $pdo = appPdo();
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
    foreach ($pdo->query("SHOW COLUMNS FROM trade_simulado") as $row) {
        $existing[(string)$row['Field']] = true;
    }
    foreach ($columns as $name => $sql) {
        if (!isset($existing[$name])) {
            $pdo->exec($sql);
        }
    }
    return $pdo;
}

function tsLiveRunId(PDO $pdo): string
{
    $row = $pdo->query("SELECT run_id FROM trade_simulado WHERE is_live = 1 ORDER BY id DESC LIMIT 1")->fetch();
    return $row ? (string)$row['run_id'] : 'live_' . date('Ymd');
}

function tsLastState(PDO $pdo, string $runId): array
{
    $stmt = $pdo->prepare("SELECT * FROM trade_simulado WHERE is_live = 1 AND run_id = :run_id ORDER BY id DESC LIMIT 1");
    $stmt->execute([':run_id' => $runId]);
    $last = $stmt->fetch();
    if (!$last) {
        return ['side' => 'FLAT', 'entry' => null, 'qty' => 0.0, 'cash' => LIVE_INITIAL_BALANCE, 'equity' => LIVE_INITIAL_BALANCE];
    }
    $side = (string)($last['position_side'] ?: 'FLAT');
    return [
        'side' => $side,
        'entry' => $last['position_entry_price'] !== null ? (float)$last['position_entry_price'] : null,
        'qty' => (float)$last['position_qty'],
        'cash' => (float)$last['cash_balance'],
        'equity' => (float)$last['equity_after'],
    ];
}

function tsUnrealized(array $state, float $price): float
{
    if ($state['side'] === 'LONG' && $state['entry']) {
        return ($price - $state['entry']) * $state['qty'];
    }
    if ($state['side'] === 'SHORT' && $state['entry']) {
        return ($state['entry'] - $price) * $state['qty'];
    }
    return 0.0;
}

function tsInsert(PDO $pdo, string $runId, array $event): void
{
    $sql = 'INSERT INTO trade_simulado (run_id, is_live, event_type, strategy_minutes, threshold_pct, initial_balance,
        balance_before, balance_after, entry_time, exit_time, side, entry_price, exit_price, btc_return_pct,
        trade_return_pct, pnl_usd, predicted_label, actual_label, was_correct, neighbors_count, similarity,
        probability_up, probability_flat, probability_down, notes, position_side, position_qty, position_entry_price,
        cash_balance, equity_after, unrealized_pnl_usd)
        VALUES (:run_id, 1, :event_type, :strategy_minutes, :threshold_pct, :initial_balance, :balance_before,
        :balance_after, :entry_time, :exit_time, :side, :entry_price, :exit_price, :btc_return_pct,
        :trade_return_pct, :pnl_usd, :predicted_label, :actual_label, :was_correct, :neighbors_count, :similarity,
        :probability_up, :probability_flat, :probability_down, :notes, :position_side, :position_qty,
        :position_entry_price, :cash_balance, :equity_after, :unrealized_pnl_usd)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':run_id' => $runId,
        ':event_type' => $event['event_type'],
        ':strategy_minutes' => LIVE_HORIZON_MINUTES,
        ':threshold_pct' => LIVE_THRESHOLD,
        ':initial_balance' => LIVE_INITIAL_BALANCE,
        ':balance_before' => $event['balance_before'],
        ':balance_after' => $event['cash_balance'],
        ':entry_time' => $event['time'],
        ':exit_time' => $event['time'],
        ':side' => $event['side'],
        ':entry_price' => $event['entry_price'],
        ':exit_price' => $event['price'],
        ':btc_return_pct' => $event['btc_return_pct'],
        ':trade_return_pct' => $event['trade_return_pct'],
        ':pnl_usd' => $event['pnl_usd'],
        ':predicted_label' => $event['predicted_label'],
        ':actual_label' => 1,
        ':was_correct' => $event['pnl_usd'] > 0 ? 1 : 0,
        ':neighbors_count' => $event['neighbors_count'],
        ':similarity' => $event['similarity'],
        ':probability_up' => $event['probability_up'],
        ':probability_flat' => $event['probability_flat'],
        ':probability_down' => $event['probability_down'],
        ':notes' => $event['notes'],
        ':position_side' => $event['position_side'],
        ':position_qty' => $event['position_qty'],
        ':position_entry_price' => $event['position_entry_price'],
        ':cash_balance' => $event['cash_balance'],
        ':equity_after' => $event['equity_after'],
        ':unrealized_pnl_usd' => $event['unrealized_pnl_usd'],
    ]);
}

function tsExecute(PDO $pdo, int $intervalSeconds): array
{
    $intervalSeconds = in_array($intervalSeconds, [60, 300], true) ? $intervalSeconds : 60;
    $runId = tsLiveRunId($pdo);
    $lastEvent = $pdo->prepare("SELECT created_at FROM trade_simulado WHERE is_live = 1 AND run_id = :run_id ORDER BY id DESC LIMIT 1");
    $lastEvent->execute([':run_id' => $runId]);
    $lastRow = $lastEvent->fetch();
    if ($lastRow && time() - strtotime((string)$lastRow['created_at']) < $intervalSeconds) {
        return tsSnapshot($pdo, $runId, 'Aguardando proximo intervalo.');
    }

    $data = spData(LIVE_HORIZON_MINUTES, LIVE_THRESHOLD);
    $prediction = $data['prediction'] ?? null;
    $latest = $data['latest'] ?? null;
    if (!$prediction || !$latest) {
        return tsSnapshot($pdo, $runId, 'Sem previsao suficiente para executar.');
    }

    $price = (float)$latest['btc'];
    $time = date('Y-m-d H:i:s', (int)$latest['time']);
    $state = tsLastState($pdo, $runId);
    $tradeLabel = (int)$prediction['label'];
    if ($tradeLabel === 1) {
        $tradeLabel = $prediction['frequencies'][2] >= $prediction['frequencies'][0] ? 2 : 0;
    }
    $desired = $tradeLabel === 2 ? 'LONG' : 'SHORT';
    if ($state['side'] === $desired) {
        return tsSnapshot($pdo, $runId, 'Posicao mantida: previsao continua na mesma direcao.');
    }

    $events = [];
    if ($state['side'] !== 'FLAT') {
        $pnl = tsUnrealized($state, $price);
        $cash = max(0.0, $state['cash'] + $pnl);
        $entry = (float)$state['entry'];
        $btcReturn = ($price / $entry - 1) * 100;
        $tradeReturn = $state['side'] === 'LONG' ? $btcReturn : -$btcReturn;
        $events[] = [
            'event_type' => 'CLOSE_' . $state['side'],
            'side' => $state['side'],
            'time' => $time,
            'price' => $price,
            'entry_price' => $entry,
            'btc_return_pct' => $btcReturn,
            'trade_return_pct' => $tradeReturn,
            'pnl_usd' => $pnl,
            'balance_before' => $state['cash'],
            'cash_balance' => $cash,
            'equity_after' => $cash,
            'unrealized_pnl_usd' => 0,
            'position_side' => 'FLAT',
            'position_qty' => 0,
            'position_entry_price' => null,
            'predicted_label' => $tradeLabel,
            'neighbors_count' => (int)$prediction['count'],
            'similarity' => (float)$prediction['similarity'],
            'probability_up' => (float)$prediction['frequencies'][2],
            'probability_flat' => (float)$prediction['frequencies'][1],
            'probability_down' => (float)$prediction['frequencies'][0],
            'notes' => 'Fechamento por troca de previsao.',
        ];
        $state = ['side' => 'FLAT', 'entry' => null, 'qty' => 0.0, 'cash' => $cash, 'equity' => $cash];
    }

    $qty = $state['cash'] / $price;
    $events[] = [
        'event_type' => 'OPEN_' . $desired,
        'side' => $desired,
        'time' => $time,
        'price' => $price,
        'entry_price' => $price,
        'btc_return_pct' => 0,
        'trade_return_pct' => 0,
        'pnl_usd' => 0,
        'balance_before' => $state['cash'],
        'cash_balance' => $state['cash'],
        'equity_after' => $state['cash'],
        'unrealized_pnl_usd' => 0,
        'position_side' => $desired,
        'position_qty' => $qty,
        'position_entry_price' => $price,
        'predicted_label' => $tradeLabel,
        'neighbors_count' => (int)$prediction['count'],
        'similarity' => (float)$prediction['similarity'],
        'probability_up' => (float)$prediction['frequencies'][2],
        'probability_flat' => (float)$prediction['frequencies'][1],
        'probability_down' => (float)$prediction['frequencies'][0],
        'notes' => ((int)$prediction['label'] === 1 ? 'Abertura pelo vies dentro da leitura lateral: ' : 'Abertura por previsao de ') . ($desired === 'LONG' ? 'alta.' : 'baixa.'),
    ];

    foreach ($events as $event) {
        tsInsert($pdo, $runId, $event);
    }
    return tsSnapshot($pdo, $runId, 'Ordem executada.');
}

function tsSnapshot(PDO $pdo, string $runId, string $message = ''): array
{
    $data = spData(LIVE_HORIZON_MINUTES, LIVE_THRESHOLD);
    $latest = $data['latest'] ?? null;
    $price = $latest ? (float)$latest['btc'] : 0.0;
    $state = tsLastState($pdo, $runId);
    $unrealized = $price > 0 ? tsUnrealized($state, $price) : 0.0;
    $equity = $state['cash'] + $unrealized;
    $stmt = $pdo->prepare("SELECT * FROM trade_simulado WHERE is_live = 1 AND run_id = :run_id ORDER BY id DESC LIMIT 80");
    $stmt->execute([':run_id' => $runId]);
    $orders = $stmt->fetchAll();
    return ['run_id' => $runId, 'message' => $message, 'price' => $price, 'state' => $state,
        'unrealized' => $unrealized, 'equity' => $equity, 'prediction' => $data['prediction'] ?? null,
        'latest' => $latest, 'orders' => $orders];
}

function brMoney(float $value): string { return '$ ' . number_format($value, 2, ',', '.'); }
function brPct(float $value): string { return ($value > 0 ? '+' : '') . number_format($value, 2, ',', '.') . '%'; }

$pdo = tsPdo();
$interval = (int)($_GET['interval'] ?? $_POST['interval'] ?? 60);
if (isset($_GET['run']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $snapshot = tsExecute($pdo, $interval);
} else {
    $snapshot = tsSnapshot($pdo, tsLiveRunId($pdo));
}
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$prediction = $snapshot['prediction'];
$state = $snapshot['state'];
$orders = $snapshot['orders'];
$realized = $state['cash'] - LIVE_INITIAL_BALANCE;
$equityPnl = $snapshot['equity'] - LIVE_INITIAL_BALANCE;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trade Simulado | Sistema Bitcoin</title>
<style>
:root{color-scheme:dark;--bg:#111416;--panel:#191e21;--line:#30373b;--text:#f0f3f4;--muted:#a3afb4;--accent:#f7b928;--up:#3cdda5;--down:#ff7182;--flat:#bdc6cc}*{box-sizing:border-box;letter-spacing:0}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Arial,sans-serif}.wrap{max-width:1480px;margin:auto;padding:26px 28px}.topbar,.brand,nav,.cards,.section-head,.controls{display:flex;align-items:center}.topbar{justify-content:space-between;gap:22px;margin-bottom:28px}.brand{gap:12px}.coin{width:42px;height:42px;border-radius:8px;background:var(--accent);color:#151515;display:grid;place-items:center;font-size:26px;font-weight:900;text-decoration:none}h1{font-size:21px;line-height:1.2;margin:0 0 3px}small{color:var(--muted)}nav{flex-wrap:wrap;gap:5px}nav a{font-size:12px;text-decoration:none;color:var(--muted);padding:8px 10px;border-radius:6px}nav a[aria-current]{background:var(--accent);color:#151515;font-weight:750}.hero{border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:22px 0;display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center}.hero h2{font-size:28px;margin:0 0 8px}.hero p{margin:0;color:var(--muted);max-width:820px}.controls{gap:10px;justify-content:flex-end;flex-wrap:wrap}.button,select{border:1px solid var(--line);border-radius:6px;background:var(--panel);color:var(--text);padding:10px 12px}.button.primary{background:var(--accent);color:#151515;border-color:transparent;font-weight:800}.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin:22px 0}.card{border:1px solid var(--line);background:var(--panel);border-radius:8px;padding:16px}.card span{display:block;color:var(--muted);font-size:12px}.card b{display:block;font-size:26px;margin-top:6px}.up{color:var(--up)}.down{color:var(--down)}.flat{color:var(--flat)}.notice{border-left:3px solid var(--accent);background:#282313;padding:12px;margin:16px 0;color:#ffe6a7}.section-head{justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:12px;margin-top:28px}h3{margin:0;font-size:17px}.table-scroll{overflow:auto;max-height:560px}table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}th{text-align:left;color:var(--muted);font-weight:600;position:sticky;top:0;background:var(--bg)}td,th{padding:10px;border-bottom:1px solid #292f33}td:last-child,th:last-child{text-align:right}.badge{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:999px;padding:3px 8px;color:var(--muted);font-size:12px}@media(max-width:900px){.wrap{padding:18px 14px}.topbar{align-items:flex-start;flex-direction:column}.hero{grid-template-columns:1fr}.cards{grid-template-columns:1fr 1fr}.hero h2{font-size:24px}}@media(max-width:520px){.cards{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
<header class="topbar"><div class="brand"><a class="coin" href="index.php">B</a><div><h1>Trade Simulado</h1><small>Execução simulada contínua</small></div></div><nav><a href="index.php">Capa</a><a href="indicadores.php">Indicadores</a><a href="super_previsao.php">Super Previsão</a><a href="trade_simulado.php" aria-current="page">Trade simulado</a></nav></header>
<main>
<section class="hero"><div><h2>Robô simulado de compra e venda</h2><p>A cada intervalo, o sistema olha a previsão dos 50 indicadores. Se der alta, compra; se der baixa, vende; se mudar de lado, fecha a posição anterior, realiza lucro ou prejuízo, e abre a nova posição. Nenhuma ordem real é enviada.</p></div><form class="controls" method="post"><select name="interval" id="interval"><option value="60" <?= $interval === 60 ? 'selected' : '' ?>>1 minuto</option><option value="300" <?= $interval === 300 ? 'selected' : '' ?>>5 minutos</option></select><button class="button primary" type="submit">Executar agora</button></form></section>
<div class="notice" id="status"><?= htmlspecialchars($snapshot['message'] ?: 'Monitorando a simulação.', ENT_QUOTES, 'UTF-8') ?></div>
<section class="cards">
  <div class="card"><span>Saldo inicial</span><b><?= brMoney(LIVE_INITIAL_BALANCE) ?></b></div>
  <div class="card"><span>Saldo realizado</span><b class="<?= $realized >= 0 ? 'up' : 'down' ?>"><?= brMoney($state['cash']) ?></b></div>
  <div class="card"><span>Lucro realizado</span><b class="<?= $realized >= 0 ? 'up' : 'down' ?>"><?= brMoney($realized) ?></b></div>
  <div class="card"><span>Lucro aberto</span><b class="<?= $snapshot['unrealized'] >= 0 ? 'up' : 'down' ?>"><?= brMoney($snapshot['unrealized']) ?></b></div>
  <div class="card"><span>Total agora</span><b class="<?= $equityPnl >= 0 ? 'up' : 'down' ?>"><?= brMoney($snapshot['equity']) ?></b></div>
  <div class="card"><span>Posição atual</span><b class="<?= $state['side'] === 'LONG' ? 'up' : ($state['side'] === 'SHORT' ? 'down' : 'flat') ?>"><?= $state['side'] === 'LONG' ? 'Comprado' : ($state['side'] === 'SHORT' ? 'Vendido' : 'Fora') ?></b></div>
  <div class="card"><span>Preço BTC</span><b><?= brMoney((float)$snapshot['price']) ?></b></div>
  <div class="card"><span>Previsão</span><b><?= $prediction ? ['Baixa','Lateral','Alta'][(int)$prediction['label']] : '--' ?></b></div>
  <div class="card"><span>Horizonte</span><b><?= LIVE_HORIZON_MINUTES ?> min</b></div>
  <div class="card"><span>Atualização</span><b id="countdown"><?= $interval === 300 ? '5 min' : '1 min' ?></b></div>
</section>
<section><div class="section-head"><h3>Ordens executadas</h3><span class="badge">run <?= htmlspecialchars((string)$snapshot['run_id'], ENT_QUOTES, 'UTF-8') ?></span></div><div class="table-scroll"><table><thead><tr><th>Hora</th><th>Ordem</th><th>Preço</th><th>Posição após</th><th>Saldo</th><th>Lucro realizado</th><th>Lucro aberto</th><th>Previsão</th></tr></thead><tbody>
<?php foreach ($orders as $row): ?>
  <?php $event = (string)$row['event_type']; $side = (string)$row['position_side']; ?>
  <tr><td><?= date('d/m H:i:s', strtotime((string)$row['created_at'])) ?></td><td class="<?= strpos($event, 'LONG') !== false ? 'up' : (strpos($event, 'SHORT') !== false ? 'down' : 'flat') ?>"><?= htmlspecialchars(str_replace(['OPEN_', 'CLOSE_', 'LONG', 'SHORT'], ['Abrir ', 'Fechar ', 'compra', 'venda'], $event), ENT_QUOTES, 'UTF-8') ?></td><td><?= brMoney((float)$row['exit_price']) ?></td><td class="<?= $side === 'LONG' ? 'up' : ($side === 'SHORT' ? 'down' : 'flat') ?>"><?= $side === 'LONG' ? 'Comprado' : ($side === 'SHORT' ? 'Vendido' : 'Fora') ?></td><td><?= brMoney((float)$row['cash_balance']) ?></td><td class="<?= (float)$row['pnl_usd'] >= 0 ? 'up' : 'down' ?>"><?= brMoney((float)$row['pnl_usd']) ?></td><td class="<?= (float)$row['unrealized_pnl_usd'] >= 0 ? 'up' : 'down' ?>"><?= brMoney((float)$row['unrealized_pnl_usd']) ?></td><td><?= ['Baixa','Lateral','Alta'][(int)$row['predicted_label']] ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
</main>
</div>
<script>
(() => {
  const seconds = <?= $interval ?>;
  let remaining = seconds;
  const countdown = document.getElementById('countdown');
  setInterval(() => {
    remaining -= 1;
    countdown.textContent = remaining > 60 ? Math.ceil(remaining / 60) + ' min' : remaining + ' s';
    if (remaining <= 0) {
      const url = new URL(location.href);
      url.searchParams.set('run', '1');
      url.searchParams.set('interval', String(seconds));
      location.href = url.toString();
    }
  }, 1000);
})();
</script>
<script src="cron_trade.js?v=<?= filemtime(__DIR__ . '/cron_trade.js') ?>" defer></script>
<script>
window.addEventListener('cron-trade:done', () => {
  if (!location.search.includes('run=1')) location.reload();
});
</script>
</body>
</html>
