<?php
declare(strict_types=1);
require_once __DIR__ . '/trade_exec.php';
function brMoney(float $value): string { return '$ ' . number_format($value, 2, ',', '.'); }
function brPct(float $value): string { return ($value > 0 ? '+' : '') . number_format($value, 2, ',', '.') . '%'; }
function brDateTime(int $time): string { return date('d/m H:i', $time); }
$pdo = tsPdo();
$interval = (int)($_POST['interval'] ?? $_GET['interval'] ?? 60);
$message = '';
if (isset($_GET['run']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $execution = tsExecute($pdo, $interval);
    $message = $execution['message'];
}
$snapshot = tsSnapshot($pdo, tsLiveRunId($pdo), $message);
header('Cache-Control: no-store');
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$prediction = $snapshot['prediction'];
$state = $snapshot['state'];
$orders = $snapshot['orders'];
$totalInitial = LIVE_INITIAL_BALANCE * count(LIVE_LANES);
$realized = $state['cash'] - $totalInitial;
$equityPnl = $snapshot['equity'] - $totalInitial;
$backtestData = spData(LIVE_HORIZON_MINUTES, LIVE_THRESHOLD);
$backtest = tsBacktest($backtestData);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trade Simulado | Sistema Bitcoin</title>
<style>
:root{color-scheme:dark;--bg:#111416;--panel:#191e21;--line:#30373b;--text:#f0f3f4;--muted:#a3afb4;--accent:#f7b928;--up:#3cdda5;--down:#ff7182;--flat:#bdc6cc}*{box-sizing:border-box;letter-spacing:0}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Arial,sans-serif}.wrap{max-width:1480px;margin:auto;padding:26px 28px}.topbar,.brand,nav,.cards,.section-head,.controls{display:flex;align-items:center}.topbar{justify-content:space-between;gap:22px;margin-bottom:28px}.brand{gap:12px}.coin{width:42px;height:42px;border-radius:8px;background:var(--accent);color:#151515;display:grid;place-items:center;font-size:26px;font-weight:900;text-decoration:none}h1{font-size:21px;line-height:1.2;margin:0 0 3px}small{color:var(--muted)}nav{flex-wrap:wrap;gap:5px}nav a{font-size:12px;text-decoration:none;color:var(--muted);padding:8px 10px;border-radius:6px}nav a[aria-current]{background:var(--accent);color:#151515;font-weight:750}.hero{border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:22px 0;display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center}.hero h2{font-size:28px;margin:0 0 8px}.hero p{margin:0;color:var(--muted);max-width:820px}.controls{gap:10px;justify-content:flex-end;flex-wrap:wrap}.button,select{border:1px solid var(--line);border-radius:6px;background:var(--panel);color:var(--text);padding:10px 12px}.button.primary{background:var(--accent);color:#151515;border-color:transparent;font-weight:800}.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin:22px 0}.card{border:1px solid var(--line);background:var(--panel);border-radius:8px;padding:16px}.card span{display:block;color:var(--muted);font-size:12px}.card b{display:block;font-size:26px;margin-top:6px}.up{color:var(--up)}.down{color:var(--down)}.flat{color:var(--flat)}.notice{border-left:3px solid var(--accent);background:#282313;padding:12px;margin:16px 0;color:#ffe6a7}.section-head{justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:12px;margin-top:28px}h3{margin:0;font-size:17px}.table-scroll{overflow:auto;max-height:560px}table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}th{text-align:left;color:var(--muted);font-weight:600;position:sticky;top:0;background:var(--bg)}td,th{padding:10px;border-bottom:1px solid #292f33}td:last-child,th:last-child{text-align:right}.badge{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:999px;padding:3px 8px;color:var(--muted);font-size:12px}@media(max-width:900px){.wrap{padding:18px 14px}.topbar{align-items:flex-start;flex-direction:column}.hero{grid-template-columns:1fr}.cards{grid-template-columns:1fr 1fr}.hero h2{font-size:24px}}@media(max-width:520px){.cards{grid-template-columns:1fr}}
nav{gap:8px}nav a{border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.025);font-weight:800;padding:9px 12px}nav a:hover{color:var(--text);background:#2b3235}nav a[aria-current]{border-color:transparent;font-weight:850}
</style>
</head>
<body>
<div class="wrap">
<header class="topbar"><div class="brand"><a class="coin" href="index.php">B</a><div><h1>Trade Simulado</h1><small>Execução simulada contínua</small></div></div><nav aria-label="Principal"><a href="index.php">Capa</a><a href="indicadores.php">Indicadores</a><a href="historico_indicadores.php">Hist&oacute;rico com gr&aacute;fico</a><a href="graficos_selecionados.php">Gr&aacute;ficos selecionados</a><a href="super_previsao.php">Super Previsão</a><a href="trade_simulado.php" aria-current="page">Trade simulado</a></nav></header>
<main>
<section class="hero"><div><h2>Robô simulado de compra e venda</h2><p>Os 50 indicadores alimentam o mesmo motor da Super Previsão. O robô só abre compra ou venda quando o sinal supera os custos e tem validação temporal positiva. Sem vantagem demonstrada, aguarda. Cada posição usa 25% do saldo, com saída prevista em 5 minutos ou antes pelos limites de risco. Nenhuma ordem real é enviada.</p></div><form class="controls" method="post"><button class="button primary" type="submit">Avaliar agora</button></form></section>
<div class="notice" id="status"><?= htmlspecialchars($snapshot['message'] ?: 'Monitorando a simulação.', ENT_QUOTES, 'UTF-8') ?></div>
<section class="cards">
  <div class="card"><span>Saldo inicial total</span><b><?= brMoney($totalInitial) ?></b><small><?= brMoney(LIVE_INITIAL_BALANCE) ?> por frente</small></div>
  <div class="card"><span>Saldo realizado total</span><b class="<?= $realized >= 0 ? 'up' : 'down' ?>"><?= brMoney($state['cash']) ?></b></div>
  <div class="card"><span>Lucro realizado</span><b class="<?= $realized >= 0 ? 'up' : 'down' ?>"><?= brMoney($realized) ?></b></div>
  <div class="card"><span>Lucro aberto</span><b class="<?= $snapshot['unrealized'] >= 0 ? 'up' : 'down' ?>"><?= brMoney($snapshot['unrealized']) ?></b></div>
  <div class="card"><span>Total agora</span><b class="<?= $equityPnl >= 0 ? 'up' : 'down' ?>"><?= brMoney($snapshot['equity']) ?></b></div>
  <div class="card"><span>Posição atual</span><b class="<?= $state['side'] === 'LONG' ? 'up' : ($state['side'] === 'SHORT' ? 'down' : 'flat') ?>"><?= $state['side'] === 'LONG' ? 'Comprado' : ($state['side'] === 'SHORT' ? 'Vendido' : 'Fora') ?></b></div>
  <div class="card"><span>Preço BTC</span><b><?= brMoney((float)$snapshot['price']) ?></b></div>
  <div class="card"><span>Previsão de mercado</span><b><?= $prediction ? ['Baixa','Lateral','Alta'][(int)$prediction['label']] : '--' ?></b></div>
  <div class="card"><span>Horizonte</span><b><?= LIVE_HORIZON_MINUTES ?> min</b></div>
  <div class="card"><span>Atualização</span><b data-cron-countdown>1 min</b></div>
</section>
<section class="cards" aria-label="Frentes do hedge">
<?php foreach ($state['lanes'] as $laneName => $lane): ?>
  <?php $laneLabel = $laneName === 'LONG_ONLY' ? 'Frente comprada' : 'Frente vendida'; ?>
  <div class="card"><span><?= $laneLabel ?></span><b class="<?= $lane['side'] === 'LONG' ? 'up' : ($lane['side'] === 'SHORT' ? 'down' : 'flat') ?>"><?= $lane['side'] === 'LONG' ? 'Comprado' : ($lane['side'] === 'SHORT' ? 'Vendido' : 'Fora') ?></b><small><?= brMoney((float)$lane['cash']) ?> realizado</small></div>
  <div class="card"><span><?= $laneLabel ?> aberto</span><b class="<?= $lane['unrealized'] >= 0 ? 'up' : 'down' ?>"><?= brMoney((float)$lane['unrealized']) ?></b><small>Total <?= brMoney((float)$lane['equity']) ?></small></div>
<?php endforeach; ?>
</section>
<section class="cards" aria-label="Qualidade do sinal">
  <div class="card"><span>Decisão do robô</span><b><?= ['Vender','Aguardar','Comprar'][$snapshot['decision']['label']] ?></b><small><?= htmlspecialchars($snapshot['decision']['reason'], ENT_QUOTES, 'UTF-8') ?></small></div>
  <div class="card"><span>Acerto de alta / baixa</span><b><?= $snapshot['metrics'] && $snapshot['metrics']['directionAccuracy'] !== null ? number_format($snapshot['metrics']['directionAccuracy']*100,1,',','.') . '%' : '--' ?></b><small><?= (int)($snapshot['metrics']['directional'] ?? 0) ?> sinais avaliados; acerto não equivale a lucro</small></div>
  <div class="card"><span>Evidências após custos</span><b><?= (int)$snapshot['evidence']['count'] ?> / <?= (int)$snapshot['policy']['minEvidence'] ?></b><small><?= $snapshot['evidence']['approved'] ? 'Critério temporal atendido' : 'Ainda sem vantagem comprovada' ?></small></div>
  <div class="card"><span>Custos por ciclo simulado</span><b><?= brPct(tradeRoundTripCost()) ?></b><small>Taxa <?= brPct($snapshot['policy']['feePct']) ?> + slippage <?= brPct($snapshot['policy']['slippagePct']) ?> por lado, sobre o valor da posição</small></div>
  <div class="card"><span>Qualidade da coleta</span><b><?= $snapshot['fresh'] ? 'Recente' : 'Desatualizada' ?></b><small><?= $snapshot['latest'] ? brDateTime((int)$snapshot['latest']['time']) : 'Sem dados' ?> · <?= (int)$backtestData['records'] ?> registros</small></div>
</section>
<div class="notice">Limite de perda: <?= brPct(-$snapshot['policy']['stopPct']) ?>; realização: <?= brPct($snapshot['policy']['takePct']) ?> sobre a posição. Saídas usam a próxima coleta disponível e podem ultrapassar o limite em saltos de preço. Custos são estimativas configuradas, provisionadas no lucro aberto e descontadas ao fechar. Venda representa uma posição SHORT sintética, sem alavancagem; não inclui funding ou empréstimo. Ordens antigas preservam os resultados e custos da versão original. O monitoramento depende do cron ou de uma tela aberta.</div>
<section><div class="section-head"><div><h3>Simulação com teste no passado</h3><small><?= (int)$backtest['source_tests'] ?> previsões em períodos não sobrepostos; mesma política de entrada e saída, usando somente evidências já concluídas. A execução contínua pode ocorrer em outros instantes.</small></div><span class="badge">backtest <?= LIVE_HORIZON_MINUTES ?> min</span></div>
<div class="cards">
  <div class="card"><span>Saldo inicial</span><b><?= brMoney((float)$backtest['initial']) ?></b></div>
  <div class="card"><span>Saldo final</span><b class="<?= $backtest['pnl'] >= 0 ? 'up' : 'down' ?>"><?= brMoney((float)$backtest['balance']) ?></b></div>
  <div class="card"><span>Lucro/prejuízo</span><b class="<?= $backtest['pnl'] >= 0 ? 'up' : 'down' ?>"><?= brMoney((float)$backtest['pnl']) ?></b></div>
  <div class="card"><span>Retorno</span><b class="<?= $backtest['return_pct'] >= 0 ? 'up' : 'down' ?>"><?= brPct((float)$backtest['return_pct']) ?></b></div>
  <div class="card"><span>Operações</span><b><?= (int)$backtest['trades'] ?></b><small><?= (int)$backtest['skipped'] ?> sinais sem entrada</small></div>
</div>
<div class="table-scroll"><table><thead><tr><th>Previsão em</th><th>Fechamento</th><th>Ordem</th><th>BTC no período</th><th>Resultado líquido</th><th>Lucro/prejuízo</th><th>Saldo após</th><th>Direção prevista</th></tr></thead><tbody>
<?php foreach (array_slice($backtest['orders'], 0, 80) as $row): ?>
<tr><td><?= brDateTime((int)$row['time']) ?></td><td><?= brDateTime((int)$row['end']) ?></td><td class="<?= $row['side'] === 'Compra' ? 'up' : 'down' ?>"><?= htmlspecialchars((string)$row['side'], ENT_QUOTES, 'UTF-8') ?></td><td><?= brPct((float)$row['btc_return']) ?></td><td class="<?= $row['trade_return'] >= 0 ? 'up' : 'down' ?>"><?= brPct((float)$row['trade_return']) ?></td><td class="<?= $row['pnl'] >= 0 ? 'up' : 'down' ?>"><?= brMoney((float)$row['pnl']) ?></td><td><?= brMoney((float)$row['balance_after']) ?></td><td class="<?= $row['correct'] ? 'up' : 'down' ?>"><?= $row['correct'] ? 'Acerto' : 'Erro' ?></td></tr>
<?php endforeach; ?>
<?php if (!$backtest['orders']): ?><tr><td colspan="8">Nenhuma entrada passou pelos filtros de custo e validação neste período. Ficar fora não comprova precisão ou rentabilidade.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section><div class="section-head"><h3>Ordens executadas</h3><span class="badge">run <?= htmlspecialchars((string)$snapshot['run_id'], ENT_QUOTES, 'UTF-8') ?></span></div><div class="table-scroll"><table><thead><tr><th>Hora</th><th>Ordem</th><th>Preço</th><th>Posição após</th><th>Saldo</th><th>Lucro realizado</th><th>Lucro aberto</th><th>Previsão de entrada</th><th>Motivo / versão</th></tr></thead><tbody>
<?php foreach ($orders as $row): ?>
  <?php $event = (string)$row['event_type']; $side = (string)$row['position_side']; ?>
  <tr><td><?= date('d/m H:i:s', strtotime((string)$row['created_at'])) ?></td><td class="<?= strpos($event, 'LONG') !== false ? 'up' : (strpos($event, 'SHORT') !== false ? 'down' : 'flat') ?>"><?= htmlspecialchars(str_replace(['OPEN_', 'CLOSE_', 'LONG', 'SHORT'], ['Abrir ', 'Fechar ', 'compra', 'venda'], $event), ENT_QUOTES, 'UTF-8') ?></td><td><?= brMoney((float)$row['exit_price']) ?></td><td class="<?= $side === 'LONG' ? 'up' : ($side === 'SHORT' ? 'down' : 'flat') ?>"><?= $side === 'LONG' ? 'Comprado' : ($side === 'SHORT' ? 'Vendido' : 'Fora') ?></td><td><?= brMoney((float)$row['cash_balance']) ?></td><td class="<?= (float)$row['pnl_usd'] >= 0 ? 'up' : 'down' ?>"><?= brMoney((float)$row['pnl_usd']) ?></td><td class="<?= (float)$row['unrealized_pnl_usd'] >= 0 ? 'up' : 'down' ?>"><?= brMoney((float)$row['unrealized_pnl_usd']) ?></td><td><?= ['Baixa','Lateral','Alta'][(int)$row['predicted_label']] ?></td><td><?= htmlspecialchars(($row['model_version'] ?? 'Legado') . ' / ' . ($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
</main>
</div>
<script src="cron_trade.js?v=<?= filemtime(__DIR__ . '/cron_trade.js') ?>" defer></script>
<script>
window.addEventListener('cron-trade:done', () => {
  location.reload();
});
</script>
</body>
</html>
