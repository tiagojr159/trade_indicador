<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/indicador_settings.php';

$error = null;
$rows = [];
$settings = indicadorSettings();
$focusedIndicators = [
    ['key' => 'indicator_14', 'label' => 'Stochastic %K/%D', 'color' => '#c084fc', 'checked' => true],
    ['key' => 'indicator_15', 'label' => 'Stoch RSI', 'color' => '#38bdf8', 'checked' => true],
    ['key' => 'indicator_18', 'label' => 'Williams %R', 'color' => '#fb7185', 'checked' => true],
    ['key' => 'indicator_21', 'label' => 'Volume relativo', 'color' => '#a3e635', 'checked' => true],
    ['key' => 'indicator_26', 'label' => 'Accumulation/Distribution', 'color' => '#f97316', 'checked' => true],
    ['key' => 'indicator_30', 'label' => 'Confirmação Preço × Volume', 'color' => '#27e6a1', 'checked' => true],
    ['key' => 'indicator_32', 'label' => 'Bollinger Bandwidth', 'color' => '#5ca8ff', 'checked' => true],
    ['key' => 'indicator_33', 'label' => 'ATR 14 %', 'color' => '#f3bd4c', 'checked' => true],
    ['key' => 'indicator_37', 'label' => 'Volatilidade Realizada', 'color' => '#ff5f73', 'checked' => true],
    ['key' => 'indicator_42', 'label' => 'Bid/Ask Spread', 'color' => '#f7b928', 'checked' => true],
];

try {
    $pdo = appPdo();
    $schema = file_get_contents(__DIR__ . '/criar_tabela_indicadores.sql');
    if ($schema !== false) {
        $pdo->exec($schema);
    }

    $stmt = $pdo->query('SELECT * FROM indicador_historico WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY created_at ASC');
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$chartRows = array_map(static function (array $row): array {
    $item = [
        'created_at' => date('d/m H:i', strtotime((string)$row['created_at'])),
        'btc_price' => isset($row['btc_price']) ? (float)$row['btc_price'] : null,
        'eth_price' => isset($row['eth_price']) ? (float)$row['eth_price'] : null,
    ];
    foreach ([14, 15, 18, 21, 26, 30, 32, 33, 37, 42] as $i) {
        $key = 'indicator_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $item[$key] = isset($row[$key]) ? (float)$row[$key] : null;
    }
    return $item;
}, $rows);
$lastSavedTimestamp = $rows ? strtotime((string)end($rows)['created_at']) : false;
$nextSaveTimestamp = $lastSavedTimestamp === false ? time() : $lastSavedTimestamp + 60;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gráficos Selecionados</title>
<style>
:root{--bg:#071019;--panel:#111d2b;--line:rgba(255,255,255,.08);--text:#eef5ff;--muted:#8fa2b8;--bull:#27e6a1;--bear:#ff5f73;--neutral:#f3bd4c;--accent:#f7b928;--blue:#5ca8ff;--radius:18px;--shadow:0 18px 50px rgba(0,0,0,.28)}
*{box-sizing:border-box}body{margin:0;color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:radial-gradient(circle at 12% -10%,rgba(247,185,40,.12),transparent 27%),radial-gradient(circle at 92% 4%,rgba(92,168,255,.11),transparent 25%),linear-gradient(180deg,#071019 0%,#0b1520 100%);min-height:100vh}.wrap{max-width:1600px;margin:auto;padding:28px 24px 60px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:24px}.brand{display:flex;gap:14px;align-items:center}.coin{width:50px;height:50px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(145deg,#f8cc52,#f4a914);color:#151515;font-weight:900;font-size:28px}.brand h1{margin:0;font-size:22px}.brand small,.muted{color:var(--muted)}.controls{display:flex;gap:8px;flex-wrap:wrap}.chip{padding:9px 12px;border:1px solid var(--line);border-radius:12px;text-decoration:none;color:var(--muted);font-size:13px;font-weight:800;background:rgba(255,255,255,.025)}.chip.active{background:var(--accent);color:#19140a;border-color:transparent}.card{background:linear-gradient(180deg,rgba(19,33,48,.92),rgba(12,24,36,.94));border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.metric{padding:16px}.metric span{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;font-weight:900}.metric b{font-size:22px}.metric .bull,.bull{color:var(--bull)}.metric .bear,.bear{color:var(--bear)}.metric .neutral,.neutral{color:var(--neutral)}.layout{display:grid;grid-template-columns:330px 1fr;gap:18px}.panel{padding:18px}.chart-card{padding:18px;min-height:660px}.checklist{display:grid;gap:8px;max-height:620px;overflow:auto;padding-right:6px}.check{display:flex;align-items:center;gap:9px;padding:9px;border-radius:12px;background:rgba(255,255,255,.035);font-size:12px;color:var(--muted);position:relative}.check input{accent-color:var(--accent)}.check span{min-width:0;flex:1}.help{width:18px;height:18px;border:1px solid rgba(247,185,40,.35);border-radius:50%;background:rgba(247,185,40,.12);color:var(--accent);font-size:11px;font-weight:900;line-height:16px;display:grid;place-items:center;cursor:help;flex:0 0 auto}.help:hover:after,.help:focus:after{content:attr(data-legend);position:absolute;left:9px;right:9px;top:calc(100% + 6px);z-index:30;padding:11px 12px;border:1px solid rgba(247,185,40,.32);border-radius:12px;background:#101c2a;color:#eef5ff;box-shadow:0 16px 38px rgba(0,0,0,.42);font-size:12px;line-height:1.45;text-align:left;font-weight:650}.actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.btn{border:1px solid var(--line);background:rgba(255,255,255,.045);color:var(--text);border-radius:12px;padding:9px 11px;font-weight:800;cursor:pointer}.legend{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;color:var(--muted);font-size:12px}.legend span{display:inline-flex;align-items:center;gap:6px}.swatch{width:10px;height:10px;border-radius:50%}canvas{width:100%;height:560px;display:block}.notice{margin-top:14px;padding:14px;border:1px solid rgba(243,189,76,.18);background:rgba(243,189,76,.055);border-radius:14px;color:#d7c69e;font-size:12px;line-height:1.5}.errors{margin-bottom:18px;padding:14px;border:1px solid rgba(255,95,115,.25);background:rgba(255,95,115,.08);border-radius:14px}.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--bull);box-shadow:0 0 16px var(--bull);margin-right:6px}@media(max-width:1000px){.summary{grid-template-columns:repeat(2,1fr)}.layout{grid-template-columns:1fr}}@media(max-width:640px){.wrap{padding:18px 12px 40px}.topbar{align-items:flex-start;flex-direction:column}.summary{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
<header class="topbar"><div class="brand"><div class="coin">B</div><div><h1>Gráficos Selecionados</h1><small><span class="dot"></span>BTC, ETH, média e sinais principais</small></div></div><nav class="controls"><a class="chip" href="index.php">Capa</a><a class="chip" href="indicadores.php">Indicadores</a><a class="chip" href="historico_indicadores.php">Histórico com gráfico</a><a class="chip active" href="graficos_selecionados.php">Gráficos selecionados</a><a class="chip" href="super_previsao.php">Super Previsão</a><a class="chip" href="trade_simulado.php">Trade simulado</a></nav></header>
<?php if ($error): ?><div class="errors"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<section class="card metric" style="margin-bottom:18px"><span>Atualização automática a cada 1 minuto</span><b id="countdown">--:--</b></section>
<section class="summary">
  <div class="card metric"><span>Tendência</span><b id="trendText" class="neutral">Aguardando</b></div>
  <div class="card metric"><span>Probabilidade de alta</span><b id="upProb">--%</b></div>
  <div class="card metric"><span>Probabilidade de baixa</span><b id="downProb">--%</b></div>
  <div class="card metric"><span>Base analisada 24h</span><b><?= count($rows) ?> registros</b></div>
</section>
<section class="layout">
  <aside class="card panel">
    <div class="muted">Linhas do gráfico</div>
    <label class="check" style="margin:12px 0"><input type="checkbox" id="onlyAverage"> <span>Mostrar só BTC + ETH + média</span></label>
    <div class="actions"><button class="btn" type="button" id="all">Todos</button><button class="btn" type="button" id="none">Limpar</button><button class="btn" type="button" id="core">BTC + ETH + Média</button></div>
    <div class="checklist" id="checks"></div>
  </aside>
  <main class="card chart-card">
    <div class="legend" id="legend"></div>
    <canvas id="chart" width="1200" height="560"></canvas>
    <div class="notice">A probabilidade é uma leitura heurística das últimas 24 horas salvas: média dos sinais selecionados, inclinação recente da média e movimento recente do BTC. Ela ajuda a leitura de tendência, mas não é recomendação automática de compra ou venda.</div>
  </main>
</section>
</div>
<script>
const rows = <?= json_encode($chartRows, JSON_UNESCAPED_UNICODE) ?>;
const focusedIndicators = <?= json_encode($focusedIndicators, JSON_UNESCAPED_UNICODE) ?>;
let nextSaveAt = <?= (int)$nextSaveTimestamp ?> * 1000;
let isCollecting = false;
const countdown = document.getElementById('countdown');
const series = [
  {key:'btc_price', label:'Bitcoin', color:'#f7b928', normalize:true, checked:true},
  {key:'eth_price', label:'Ethereum', color:'#5ca8ff', normalize:true, checked:true},
  {key:'median', label:'Média dos indicadores', color:'#eef5ff', normalize:false, checked:true, median:true},
  ...focusedIndicators
];
const checks = document.getElementById('checks');
const onlyAverage = document.getElementById('onlyAverage');
const legend = document.getElementById('legend');
const canvas = document.getElementById('chart');
const ctx = canvas.getContext('2d');

function formatRemaining(ms) {
  const total = Math.max(0, Math.ceil(ms / 1000));
  const minutes = Math.floor(total / 60);
  const seconds = total % 60;
  return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
}

async function collectWhenDue() {
  if (isCollecting) return;
  isCollecting = true;
  countdown.textContent = 'salvando...';
  try {
    const response = await fetch('coletar_indicadores.php?auto=1&save_interval_minutes=1', {cache: 'no-store'});
    const data = await response.json();
    if (data.saved) {
      window.location.reload();
      return;
    }
    nextSaveAt = data.next_save_at ? Date.parse(data.next_save_at) : Date.now() + 60000;
  } catch (error) {
    nextSaveAt = Date.now() + 60000;
  } finally {
    isCollecting = false;
  }
}

function tickAutoUpdate() {
  const remaining = nextSaveAt - Date.now();
  countdown.textContent = formatRemaining(remaining);
  if (remaining <= 0) {
    collectWhenDue();
  }
}

function indicatorSeries() { return series.filter(s => !s.normalize && !s.median); }
function activeIndicatorKeys() {
  if (onlyAverage.checked) return indicatorSeries().map(s => s.key);
  const selected = indicatorSeries().filter(s => document.querySelector(`[data-key="${s.key}"]`)?.checked).map(s => s.key);
  return selected.length ? selected : indicatorSeries().map(s => s.key);
}
function pct(a, b) { return b === 0 || b === null || a === null ? 0 : ((a - b) / b) * 100; }

function medianValues() {
  const keys = activeIndicatorKeys();
  return rows.map(row => {
    const vals = keys.map(key => row[key]).filter(v => v !== null && !Number.isNaN(v));
    if (!vals.length) return null;
    return vals.reduce((sum, value) => sum + value, 0) / vals.length;
  });
}

function valuesFor(s) {
  if (s.median) return medianValues();
  const values = rows.map(row => row[s.key]).filter(v => v !== null && !Number.isNaN(v));
  if (!s.normalize) return rows.map(row => row[s.key]);
  if (!values.length) return rows.map(() => null);
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = Math.max(max - min, 0.000001);
  return rows.map(row => row[s.key] === null ? null : ((row[s.key] - min) / range) * 4 - 2);
}

function updateTrend() {
  const vals = medianValues().filter(v => v !== null);
  const btcVals = rows.map(row => row.btc_price).filter(v => v !== null);
  if (vals.length < 3) {
    document.getElementById('trendText').textContent = 'Sem dados';
    document.getElementById('upProb').textContent = '--%';
    document.getElementById('downProb').textContent = '--%';
    return;
  }
  const last = vals[vals.length - 1];
  const prevIdx = Math.max(0, vals.length - 8);
  const slope = last - vals[prevIdx];
  const recent = vals.slice(-Math.min(12, vals.length));
  const recentAvg = recent.reduce((a, b) => a + b, 0) / recent.length;
  const btcMove = btcVals.length >= 6 ? pct(btcVals[btcVals.length - 1], btcVals[Math.max(0, btcVals.length - 6)]) : 0;
  const signal = Math.max(-1, Math.min(1, recentAvg / 2)) * 0.55 + Math.max(-1, Math.min(1, slope / 2)) * 0.30 + Math.max(-1, Math.min(1, btcMove / 1.5)) * 0.15;
  const up = Math.round(Math.max(5, Math.min(95, 50 + signal * 45)));
  const down = 100 - up;
  const trend = up >= 60 ? ['Tendência de alta', 'bull'] : (up <= 40 ? ['Tendência de baixa', 'bear'] : ['Tendência lateral', 'neutral']);
  const trendEl = document.getElementById('trendText');
  trendEl.textContent = trend[0];
  trendEl.className = trend[1];
  document.getElementById('upProb').textContent = up + '%';
  document.getElementById('downProb').textContent = down + '%';
}

function indicatorLegend(name) {
  const defs = [
    ['Bitcoin', 'Preço do BTC normalizado no eixo do gráfico para comparar sua oscilação com os indicadores.'],
    ['Ethereum', 'Preço do ETH normalizado no eixo do gráfico para comparar sua oscilação com BTC e os indicadores.'],
    ['Média', 'Média simples dos indicadores selecionados. No modo “só BTC + ETH + média”, usa todos os indicadores focados e oculta as linhas individuais.'],
    ['Stochastic', 'Oscilador Estocástico: %K mostra onde o fechamento está dentro da faixa máxima-mínima recente; %D é a média suavizada do %K. Acima de 80 sugere sobrecompra e abaixo de 20 sobrevenda.'],
    ['Stoch RSI', 'Aplica a fórmula estocástica ao RSI, não ao preço. Mostra se o RSI está perto do topo ou da base da própria faixa recente.'],
    ['Williams', 'Williams %R mede o fechamento dentro da faixa máxima-mínima recente em escala de 0 a -100. Perto de 0 indica força; perto de -100 indica fraqueza.'],
    ['Volume relativo', 'Compara o volume atual com a média recente. Volume acima da média aumenta a confiança no movimento do candle.'],
    ['Accumulation', 'Linha de Acumulação/Distribuição combina local do fechamento no candle com volume. Alta na linha indica fluxo acumulador.'],
    ['Confirmação', 'Confirma se a direção do preço veio acompanhada por expansão de volume. Movimento com volume tende a ter mais validade.'],
    ['Bollinger Bandwidth', 'Mede a largura das Bandas de Bollinger. Largura aumentando indica volatilidade em expansão; contraindo indica compressão.'],
    ['ATR', 'Average True Range mede volatilidade média. ATR maior indica candles mais amplos, mas não define direção sozinho.'],
    ['Volatilidade Realizada', 'Volatilidade calculada pelos retornos recentes. Quando sobe, o mercado está se movendo com mais intensidade.'],
    ['Bid/Ask', 'Spread entre melhor compra e melhor venda. Spread estreito indica execução mais eficiente; spread largo indica menor liquidez.']
  ];
  const match = defs.find(([needle]) => name.toLowerCase().includes(needle.toLowerCase()));
  return match ? match[1] : 'Indicador técnico usado como leitura auxiliar de tendência, momentum, volume, volatilidade ou posicionamento.';
}

function escapeAttr(value) {
  return String(value).replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[char]));
}

function buildChecks() {
  checks.innerHTML = '';
  series.forEach(item => {
    const label = document.createElement('label');
    label.className = 'check';
    const help = indicatorLegend(item.label);
    label.innerHTML = `<input type="checkbox" data-key="${item.key}" ${item.checked ? 'checked' : ''}> <span>${escapeAttr(item.label)}</span> <button class="help" type="button" title="${escapeAttr(help)}" data-legend="${escapeAttr(help)}" aria-label="Descrição de ${escapeAttr(item.label)}">?</button>`;
    checks.appendChild(label);
  });
}

function buildLegend(active) {
  legend.innerHTML = '';
  active.forEach(s => {
    const item = document.createElement('span');
    item.innerHTML = `<i class="swatch" style="background:${s.color}"></i>${s.label}`;
    legend.appendChild(item);
  });
}

function draw() {
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  canvas.width = Math.max(650, Math.floor(rect.width * dpr));
  canvas.height = Math.floor(560 * dpr);
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  const w = canvas.width / dpr;
  const h = canvas.height / dpr;
  ctx.clearRect(0, 0, w, h);
  ctx.fillStyle = '#0b1520';
  ctx.fillRect(0, 0, w, h);
  const pad = {l:52, r:20, t:24, b:56};
  const plotW = w - pad.l - pad.r;
  const plotH = h - pad.t - pad.b;
  ctx.strokeStyle = 'rgba(255,255,255,.08)';
  ctx.lineWidth = 1;
  for (let y = -2; y <= 2; y++) {
    const py = pad.t + (2 - y) / 4 * plotH;
    ctx.beginPath(); ctx.moveTo(pad.l, py); ctx.lineTo(w - pad.r, py); ctx.stroke();
    ctx.fillStyle = '#8fa2b8'; ctx.font = '12px Arial'; ctx.fillText(String(y), 18, py + 4);
  }
  if (!rows.length) {
    ctx.fillStyle = '#8fa2b8'; ctx.font = '16px Arial'; ctx.fillText('Nenhum registro gravado ainda.', pad.l, pad.t + 40);
    updateTrend();
    return;
  }
  const active = onlyAverage.checked
    ? series.filter(s => ['btc_price', 'eth_price', 'median'].includes(s.key))
    : series.filter(s => document.querySelector(`[data-key="${s.key}"]`)?.checked);
  buildLegend(active);
  active.forEach(s => {
    const vals = valuesFor(s);
    ctx.strokeStyle = s.color;
    ctx.lineWidth = s.median ? 3.4 : (s.normalize ? 2.3 : 1.6);
    ctx.beginPath();
    let started = false;
    vals.forEach((v, idx) => {
      if (v === null || Number.isNaN(v)) return;
      const x = pad.l + (rows.length === 1 ? 0 : idx / (rows.length - 1) * plotW);
      const y = pad.t + (2 - Math.max(-2, Math.min(2, v))) / 4 * plotH;
      if (!started) { ctx.moveTo(x, y); started = true; } else { ctx.lineTo(x, y); }
    });
    ctx.stroke();
  });
  ctx.fillStyle = '#8fa2b8';
  ctx.font = '11px Arial';
  const step = Math.max(1, Math.ceil(rows.length / 8));
  rows.forEach((row, idx) => {
    if (idx % step !== 0 && idx !== rows.length - 1) return;
    const x = pad.l + (rows.length === 1 ? 0 : idx / (rows.length - 1) * plotW);
    ctx.fillText(row.created_at, Math.min(x, w - 70), h - 22);
  });
  updateTrend();
}

buildChecks();
checks.addEventListener('click', event => {
  if (event.target.closest('.help')) {
    event.preventDefault();
    event.stopPropagation();
  }
});
checks.addEventListener('change', draw);
onlyAverage.addEventListener('change', draw);
document.getElementById('all').addEventListener('click', () => { checks.querySelectorAll('input').forEach(i => i.checked = true); draw(); });
document.getElementById('none').addEventListener('click', () => { checks.querySelectorAll('input').forEach(i => i.checked = false); draw(); });
document.getElementById('core').addEventListener('click', () => { checks.querySelectorAll('input').forEach(i => i.checked = ['btc_price','eth_price','median'].includes(i.dataset.key)); draw(); });
window.addEventListener('resize', draw);
setInterval(tickAutoUpdate, 1000);
tickAutoUpdate();
draw();
</script>
<script src="cron_trade.js?v=<?= filemtime(__DIR__ . '/cron_trade.js') ?>" defer></script>
</body>
</html>
