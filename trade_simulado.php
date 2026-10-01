<?php
declare(strict_types=1);
require_once __DIR__ . '/trade_exec.php';

function brMoney(float $value): string { return '$ ' . number_format($value, 2, ',', '.'); }
function brPct(float $value): string { return ($value > 0 ? '+' : '') . number_format($value, 2, ',', '.') . '%'; }
function brDateTime(int $time): string { return date('d/m H:i', $time); }
function tradeSideLabel(string $side): string { return $side === 'LONG' ? 'Comprado' : ($side === 'SHORT' ? 'Vendido' : 'Fora'); }
function tradeSignalLabel(int $label): string { return $label === 2 ? 'Comprar' : ($label === 0 ? 'Vender' : 'Aguardar'); }

$pdo = tsPdo();
$runId = tsLiveRunId($pdo);
$selected = (string)($_GET['strategy'] ?? $_POST['strategy'] ?? 'median');
if (!isset(LIVE_STRATEGIES[$selected])) $selected = 'median';
$data = spData(LIVE_HORIZON_MINUTES, LIVE_THRESHOLD, true);
$message = '';
if (isset($_GET['run']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $interval = (int)($_POST['interval'] ?? $_GET['interval'] ?? 60);
    $execution = tsExecute($pdo, $interval, $data);
    $message = (string)$execution['message'];
    $runId = tsLiveRunId($pdo);
}
$snapshot = tsSnapshot($pdo, $runId, $message, $data, $selected);
if (($_GET['format'] ?? '') === 'json') {
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$accounts = $snapshot['state']['lanes'];
$selectedAccount = $accounts[$selected];
$strategies = tsStrategies();
$backtests = [];
foreach ($strategies as $key => $_strategy) $backtests[$key] = tsBacktestStrategy($key, $data);
$totalInitial = LIVE_INITIAL_BALANCE * count($strategies);
$totalCash = array_sum(array_column($accounts, 'cash'));
$totalOpen = array_sum(array_column($accounts, 'unrealized'));
$totalEquity = $totalCash + $totalOpen;
$historicalRank = array_keys($strategies);
usort($historicalRank, static fn(string $a, string $b): int => $backtests[$b]['balance'] <=> $backtests[$a]['balance']);
$signal = $selectedAccount['signal'];
$statusMessage = $message ?: $signal['reason'];
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trade Simulado | Sistema Bitcoin</title>
<style>
:root{color-scheme:dark;--bg:#111416;--panel:#191e21;--line:#30373b;--text:#f0f3f4;--muted:#a3afb4;--accent:#f7b928;--up:#3cdda5;--down:#ff7182;--flat:#bdc6cc}*{box-sizing:border-box;letter-spacing:0}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Arial,sans-serif}.wrap{max-width:1480px;margin:auto;padding:26px 28px}.topbar,.brand,nav,.section-head{display:flex;align-items:center}.topbar{justify-content:space-between;gap:22px;margin-bottom:28px}.brand{gap:12px}.coin{width:42px;height:42px;border-radius:8px;background:var(--accent);color:#151515;display:grid;place-items:center;font-size:26px;font-weight:900;text-decoration:none}h1{font-size:21px;line-height:1.2;margin:0 0 3px}small{color:var(--muted)}nav{flex-wrap:wrap;gap:5px}nav a{font-size:12px;text-decoration:none;color:var(--muted);padding:8px 10px;border-radius:6px}nav a[aria-current]{background:var(--accent);color:#151515;font-weight:750}.hero{border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:22px 0;display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center}.hero h2{font-size:26px;margin:0 0 8px}.hero p{margin:0;color:var(--muted);max-width:900px}.button{border:1px solid var(--line);border-radius:7px;background:var(--panel);color:var(--text);padding:10px 14px;cursor:pointer}.button.primary{background:var(--accent);color:#151515;border-color:transparent;font-weight:800}.notice{border-left:3px solid var(--accent);background:#282313;padding:12px;margin:16px 0;color:#ffe6a7}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:18px 0}.card{border:1px solid var(--line);background:var(--panel);border-radius:8px;padding:15px;min-width:0}.card span{display:block;color:var(--muted);font-size:12px}.card b{display:block;font-size:23px;margin-top:5px;overflow-wrap:anywhere}.up{color:var(--up)}.down{color:var(--down)}.flat{color:var(--flat)}.tabs{display:flex;gap:8px;overflow-x:auto;padding:4px 0 12px;border-bottom:1px solid var(--line)}.tab{flex:0 0 auto;min-width:150px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--panel);color:var(--muted);text-decoration:none}.tab strong,.tab small{display:block}.tab strong{font-size:13px;color:var(--text)}.tab[aria-selected="true"]{border-color:var(--accent);background:#282313}.tab[aria-selected="true"] strong{color:var(--accent)}.section-head{justify-content:space-between;gap:12px;border-bottom:1px solid var(--line);padding-bottom:12px;margin-top:28px}h3{margin:0;font-size:17px}.badge{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:999px;padding:3px 8px;color:var(--muted);font-size:12px}.table-scroll{overflow:auto;max-height:560px}table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}th{text-align:left;color:var(--muted);font-weight:600;position:sticky;top:0;background:var(--bg)}td,th{padding:10px;border-bottom:1px solid #292f33}td:last-child,th:last-child{text-align:right}.description{color:var(--muted);margin:12px 0 0}.section-note{color:var(--muted);font-size:12px;margin:10px 0}.rank{color:var(--accent);font-weight:800}.positive{color:var(--up)}.negative{color:var(--down)}@media(max-width:900px){.wrap{padding:18px 14px}.topbar{align-items:flex-start;flex-direction:column}.hero{grid-template-columns:1fr}.cards{grid-template-columns:1fr 1fr}}@media(max-width:520px){.cards{grid-template-columns:1fr}.hero h2{font-size:23px}}
nav{gap:8px}nav a{border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.025);font-weight:800;padding:9px 12px}nav a:hover{color:var(--text);background:#2b3235}nav a[aria-current]{border-color:transparent;font-weight:850}
</style>
</head>
<body>
<div class="wrap">
<header class="topbar"><div class="brand"><a class="coin" href="index.php">B</a><div><h1>Trade Simulado</h1><small>Sete estratégias, sete contas independentes</small></div></div><nav aria-label="Principal"><a href="index.php">Capa</a><a href="indicadores.php">Indicadores</a><a href="historico_indicadores.php">Histórico com gráfico</a><a href="graficos_selecionados.php">Gráficos selecionados</a><a href="super_previsao.php">Super Previsão</a><a href="trade_simulado.php" aria-current="page">Trade simulado</a></nav></header>
<main>
<section class="hero"><div><h2>Comparação de estratégias de compra e venda</h2><p>Cada aba acompanha uma conta virtual iniciada com US$ 100. As estratégias usam sinais diferentes dos 50 indicadores; cada saldo e cada posição ficam separados. A estratégia de retorno à mediana compara a linha do BTC reescalada no gráfico com a média branca dos dez indicadores selecionados. Toda operação continua simulada.</p></div><form method="post"><input type="hidden" name="strategy" value="<?= htmlspecialchars($selected, ENT_QUOTES, 'UTF-8') ?>"><button class="button primary" type="submit">Avaliar agora</button></form></section>
<div class="notice" id="status"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?> · <?= $snapshot['fresh'] ? 'cotação recente' : 'cotação sem atualização recente; não abre novas posições' ?></div>
<section class="cards" aria-label="Resumo das sete contas">
  <div class="card"><span>Saldo inicial total</span><b><?= brMoney($totalInitial) ?></b><small><?= brMoney(LIVE_INITIAL_BALANCE) ?> em cada uma das <?= count($strategies) ?> contas</small></div>
  <div class="card"><span>Saldo realizado total</span><b class="<?= $totalCash >= $totalInitial ? 'up' : 'down' ?>"><?= brMoney($totalCash) ?></b></div>
  <div class="card"><span>Lucro aberto</span><b class="<?= $totalOpen >= 0 ? 'up' : 'down' ?>"><?= brMoney($totalOpen) ?></b></div>
  <div class="card"><span>Patrimônio total</span><b class="<?= $totalEquity >= $totalInitial ? 'up' : 'down' ?>"><?= brMoney($totalEquity) ?></b></div>
</section>
<nav class="tabs" role="tablist" aria-label="Estratégias simuladas">
<?php foreach ($strategies as $key => $strategy): ?>
  <a class="tab" role="tab" href="trade_simulado.php?strategy=<?= rawurlencode($key) ?>" aria-selected="<?= $selected === $key ? 'true' : 'false' ?>"><strong><?= htmlspecialchars($strategy['name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= brMoney((float)$accounts[$key]['equity']) ?> agora</small></a>
<?php endforeach; ?>
</nav>
<section aria-labelledby="selected-title">
<div class="section-head"><div><h3 id="selected-title">Conta: <?= htmlspecialchars($strategies[$selected]['name'], ENT_QUOTES, 'UTF-8') ?></h3><p class="description"><?= htmlspecialchars($strategies[$selected]['description'], ENT_QUOTES, 'UTF-8') ?></p></div><span class="badge">Conta independente · inicial <?= brMoney(LIVE_INITIAL_BALANCE) ?></span></div>
<section class="cards" aria-label="Resumo da estratégia selecionada">
  <div class="card"><span>Saldo realizado</span><b class="<?= $selectedAccount['cash'] >= LIVE_INITIAL_BALANCE ? 'up' : 'down' ?>"><?= brMoney((float)$selectedAccount['cash']) ?></b></div>
  <div class="card"><span>Patrimônio agora</span><b class="<?= $selectedAccount['equity'] >= LIVE_INITIAL_BALANCE ? 'up' : 'down' ?>"><?= brMoney((float)$selectedAccount['equity']) ?></b></div>
  <div class="card"><span>Posição</span><b class="<?= $selectedAccount['side'] === 'LONG' ? 'up' : ($selectedAccount['side'] === 'SHORT' ? 'down' : 'flat') ?>"><?= tradeSideLabel($selectedAccount['side']) ?></b></div>
  <div class="card"><span>Decisão atual</span><b class="<?= $signal['label'] === 2 ? 'up' : ($signal['label'] === 0 ? 'down' : 'flat') ?>"><?= tradeSignalLabel((int)$signal['label']) ?></b><small><?= htmlspecialchars($signal['reason'], ENT_QUOTES, 'UTF-8') ?></small></div>
  <div class="card"><span>Preço BTC</span><b><?= brMoney((float)$snapshot['price']) ?></b></div>
  <div class="card"><span>Lucro aberto</span><b class="<?= $selectedAccount['unrealized'] >= 0 ? 'up' : 'down' ?>"><?= brMoney((float)$selectedAccount['unrealized']) ?></b></div>
  <div class="card"><span>Resultado no replay</span><b class="<?= $backtests[$selected]['pnl'] >= 0 ? 'up' : 'down' ?>"><?= brMoney((float)$backtests[$selected]['pnl']) ?></b><small>Saldo final <?= brMoney((float)$backtests[$selected]['balance']) ?></small></div>
  <div class="card"><span>Operações no replay</span><b><?= (int)$backtests[$selected]['trades'] ?></b><small><?= $backtests[$selected]['win_rate'] === null ? 'Sem operações' : number_format($backtests[$selected]['win_rate']*100,1,',','.') . '% com lucro' ?></small></div>
</section>
<p class="section-note">Política comum: posição de 25% do saldo, horizonte de <?= LIVE_HORIZON_MINUTES ?> minutos, stop de <?= brPct(-tradePolicy()['stopPct']) ?>, alvo de <?= brPct(tradePolicy()['takePct']) ?> e custo estimado de <?= brPct(tradeRoundTripCost()) ?> por ciclo completo.</p>
</section>

<section><div class="section-head"><div><h3>Comparação no histórico armazenado</h3><small>Replay sequencial com os mesmos custos, tamanho de posição e limites de risco. A ordem das linhas usa o saldo final do replay.</small></div><span class="badge"><?= (int)$backtests[$selected]['source_tests'] ?> pontos avaliados</span></div>
<div class="table-scroll"><table><thead><tr><th>Ordem</th><th>Estratégia / conta</th><th>Saldo ao vivo</th><th>Posição</th><th>Saldo final no replay</th><th>Lucro no replay</th><th>Operações</th><th>Acerto</th><th>Queda máxima</th></tr></thead><tbody>
<?php foreach ($historicalRank as $rank => $key): $bt=$backtests[$key]; $account=$accounts[$key]; ?>
<tr><td class="rank">#<?= $rank+1 ?></td><td><a href="trade_simulado.php?strategy=<?= rawurlencode($key) ?>" style="color:inherit"><?= htmlspecialchars($strategies[$key]['name'], ENT_QUOTES, 'UTF-8') ?></a></td><td><?= brMoney((float)$account['equity']) ?></td><td class="<?= $account['side']==='LONG'?'up':($account['side']==='SHORT'?'down':'flat') ?>"><?= tradeSideLabel($account['side']) ?></td><td><?= brMoney((float)$bt['balance']) ?></td><td class="<?= $bt['pnl']>=0?'up':'down' ?>"><?= brMoney((float)$bt['pnl']) ?> (<?= brPct((float)$bt['return_pct']) ?>)</td><td><?= (int)$bt['trades'] ?></td><td><?= $bt['win_rate']===null?'--':number_format($bt['win_rate']*100,1,',','.').'%' ?></td><td><?= brPct((float)$bt['drawdown']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="section-note">O replay percorre os registros disponíveis em ordem e só usa indicadores e preços anteriores à entrada. Ele compara este conjunto de dados; não garante resultado futuro. Uma estratégia sem operações mantém os US$ 100 e não deve ser interpretada como vencedora.</p>
</section>

<section><div class="section-head"><h3>Operações históricas desta estratégia</h3><span class="badge"><?= (int)$backtests[$selected]['trades'] ?> entradas</span></div><div class="table-scroll"><table><thead><tr><th>Entrada</th><th>Fechamento</th><th>Ordem</th><th>Variação BTC</th><th>Resultado líquido</th><th>Lucro/prejuízo</th><th>Saldo após</th><th>Motivo de saída</th></tr></thead><tbody>
<?php foreach (array_slice($backtests[$selected]['orders'],0,80) as $row): ?>
<tr><td><?= brDateTime((int)$row['time']) ?></td><td><?= brDateTime((int)$row['end']) ?></td><td class="<?= $row['side']==='Compra'?'up':'down' ?>"><?= $row['side'] ?></td><td><?= brPct((float)$row['btc_return']) ?></td><td class="<?= $row['trade_return']>=0?'up':'down' ?>"><?= brPct((float)$row['trade_return']) ?></td><td class="<?= $row['pnl']>=0?'up':'down' ?>"><?= brMoney((float)$row['pnl']) ?></td><td><?= brMoney((float)$row['balance_after']) ?></td><td><?= htmlspecialchars((string)$row['reason'], ENT_QUOTES, 'UTF-8') ?></td></tr>
<?php endforeach; ?>
<?php if (!$backtests[$selected]['orders']): ?><tr><td colspan="8">Ainda não houve sinal de entrada que atendesse às regras desta estratégia no período armazenado.</td></tr><?php endif; ?>
</tbody></table></div></section>

<section><div class="section-head"><div><h3>Registro ao vivo da conta selecionada</h3><small>Run <?= htmlspecialchars((string)$snapshot['run_id'], ENT_QUOTES, 'UTF-8') ?> · cada operação simulada aparece nesta conta</small></div></div><div class="table-scroll"><table><thead><tr><th>Hora</th><th>Ordem</th><th>Preço</th><th>Posição após</th><th>Saldo</th><th>Lucro realizado</th><th>Lucro aberto</th><th>Previsão de entrada</th><th>Motivo</th></tr></thead><tbody>
<?php foreach ($snapshot['orders'] as $row): $event=(string)$row['event_type'];$side=(string)$row['position_side']; ?>
<tr><td><?= date('d/m H:i:s',strtotime((string)$row['created_at'])) ?></td><td class="<?= strpos($event,'LONG')!==false?'up':(strpos($event,'SHORT')!==false?'down':'flat') ?>"><?= htmlspecialchars(str_replace(['OPEN_','CLOSE_','LONG','SHORT'],['Abrir ','Fechar ','compra','venda'],$event),ENT_QUOTES,'UTF-8') ?></td><td><?= brMoney((float)$row['exit_price']) ?></td><td class="<?= $side==='LONG'?'up':($side==='SHORT'?'down':'flat') ?>"><?= tradeSideLabel($side) ?></td><td><?= brMoney((float)$row['cash_balance']) ?></td><td class="<?= (float)$row['pnl_usd']>=0?'up':'down' ?>"><?= brMoney((float)$row['pnl_usd']) ?></td><td class="<?= (float)$row['unrealized_pnl_usd']>=0?'up':'down' ?>"><?= brMoney((float)$row['unrealized_pnl_usd']) ?></td><td><?= ['Baixa','Lateral','Alta'][(int)$row['predicted_label']] ?></td><td><?= htmlspecialchars((string)($row['notes']??''),ENT_QUOTES,'UTF-8') ?></td></tr>
<?php endforeach; ?>
<?php if (!$snapshot['orders']): ?><tr><td colspan="9">Esta conta ainda não registrou operações ao vivo.</td></tr><?php endif; ?>
</tbody></table></div></section>
<div class="notice">As sete contas começam com US$ 100 cada. O simulador não envia ordens reais. A taxa e o slippage são estimativas configuradas; a venda é uma posição SHORT sintética, sem alavancagem nem funding. O acompanhamento contínuo depende do cron ou de uma tela aberta.</div>
</main>
</div>
<script src="cron_trade.js?v=<?= filemtime(__DIR__ . '/cron_trade.js') ?>" defer></script>
<script>window.addEventListener('cron-trade:done',()=>location.reload());</script>
</body>
</html>
