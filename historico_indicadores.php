<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/indicador_settings.php';

$rows = [];
$indicatorNames = [];
$error = null;
$notice = null;
$settings = indicadorSettings();
$chartSelection = indicadorChartSelection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $settings = indicadorSaveSettings((int)($_POST['save_interval_minutes'] ?? 1));
        $notice = 'Intervalo de gravação atualizado.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    $pdo = appPdo();
    $schema = file_get_contents(__DIR__ . '/criar_tabela_indicadores.sql');
    if ($schema !== false) {
        $pdo->exec($schema);
    }

    $stmt = $pdo->query('SELECT * FROM indicador_historico WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY created_at ASC');
    $rows = $stmt->fetchAll();
    foreach (array_reverse($rows) as $row) {
        if (!empty($row['indicator_names'])) {
            $decoded = json_decode((string)$row['indicator_names'], true);
            if (is_array($decoded)) {
                $indicatorNames = $decoded;
                break;
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$saveIntervalMinutes = (int)$settings['save_interval_minutes'];
$saveIntervalSeconds = $saveIntervalMinutes * 60;
$lastSavedTimestamp = $rows ? strtotime((string)end($rows)['created_at']) : false;
$nextSaveTimestamp = $lastSavedTimestamp === false ? time() : $lastSavedTimestamp + $saveIntervalSeconds;

if (!$indicatorNames) {
    $indicatorNames = array_map(static fn(int $i): string => 'Indicador ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), range(1, 50));
}

$chartRows = array_map(static function (array $row): array {
    $item = [
        'created_at' => date('d/m H:i', strtotime((string)$row['created_at'])),
        'btc_price' => isset($row['btc_price']) ? (float)$row['btc_price'] : null,
        'eth_price' => isset($row['eth_price']) ? (float)$row['eth_price'] : null,
    ];
    for ($i = 1; $i <= 50; $i++) {
        $key = 'indicator_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $item[$key] = isset($row[$key]) ? (float)$row[$key] : null;
    }
    return $item;
}, $rows);
$selectedChartSeries = $chartSelection['selected_series'] ?? [];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Histórico dos Indicadores</title>
<style>
:root{--bg:#071019;--panel:#111d2b;--line:rgba(255,255,255,.08);--text:#eef5ff;--muted:#8fa2b8;--bull:#27e6a1;--bear:#ff5f73;--neutral:#f3bd4c;--accent:#f7b928;--blue:#5ca8ff;--radius:22px;--shadow:0 18px 50px rgba(0,0,0,.28)}*{box-sizing:border-box}body{margin:0;color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:radial-gradient(circle at 12% -10%,rgba(247,185,40,.12),transparent 27%),radial-gradient(circle at 92% 4%,rgba(92,168,255,.11),transparent 25%),linear-gradient(180deg,#071019 0%,#0b1520 100%);min-height:100vh}.wrap{max-width:1600px;margin:auto;padding:28px 24px 60px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:24px}.brand{display:flex;gap:14px;align-items:center}.coin{width:50px;height:50px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(145deg,#f8cc52,#f4a914);color:#151515;font-weight:900;font-size:28px}.brand h1{margin:0;font-size:22px}.brand small,.muted{color:var(--muted)}.controls{display:flex;gap:8px;flex-wrap:wrap}.chip{padding:9px 12px;border:1px solid var(--line);border-radius:12px;text-decoration:none;color:var(--muted);font-size:13px;font-weight:800;background:rgba(255,255,255,.025)}.chip.active{background:var(--accent);color:#19140a;border-color:transparent}.card{background:linear-gradient(180deg,rgba(19,33,48,.92),rgba(12,24,36,.94));border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}.layout{display:grid;grid-template-columns:320px 1fr;gap:18px}.panel{padding:18px}.chart-card{padding:18px;min-height:620px}.checklist{display:grid;gap:8px;max-height:520px;overflow:auto;padding-right:6px}.check{display:flex;align-items:center;gap:9px;padding:9px;border-radius:12px;background:rgba(255,255,255,.035);font-size:12px;color:var(--muted);position:relative}.check input{accent-color:var(--accent)}.check span{min-width:0;flex:1}.help{width:18px;height:18px;border:1px solid rgba(247,185,40,.35);border-radius:50%;background:rgba(247,185,40,.12);color:var(--accent);font-size:11px;font-weight:900;line-height:16px;display:grid;place-items:center;cursor:help;flex:0 0 auto}.help:hover:after,.help:focus:after{content:attr(data-legend);position:absolute;left:9px;right:9px;top:calc(100% + 6px);z-index:30;padding:11px 12px;border:1px solid rgba(247,185,40,.32);border-radius:12px;background:#101c2a;color:#eef5ff;box-shadow:0 16px 38px rgba(0,0,0,.42);font-size:12px;line-height:1.45;text-align:left;font-weight:650}.actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.btn{border:1px solid var(--line);background:rgba(255,255,255,.045);color:var(--text);border-radius:12px;padding:9px 11px;font-weight:800;cursor:pointer}.settings{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:18px;padding:14px 16px}.settings label{display:grid;gap:5px;color:var(--muted);font-size:11px;text-transform:uppercase;font-weight:900}.settings select{min-width:190px;border:1px solid var(--line);background:#0b1520;color:var(--text);border-radius:12px;padding:10px 12px;font-weight:800}.timer{text-align:right;color:var(--muted);font-size:12px}.timer b{display:block;color:var(--text);font-size:18px}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}.metric{padding:13px;border-radius:14px;background:rgba(255,255,255,.035)}.metric span{display:block;color:var(--muted);font-size:11px;text-transform:uppercase}.metric b{font-size:18px}canvas{width:100%;height:520px;display:block}.notice{margin-top:14px;padding:14px;border:1px solid rgba(243,189,76,.18);background:rgba(243,189,76,.055);border-radius:14px;color:#d7c69e;font-size:12px;line-height:1.5}.ok{margin-bottom:18px;padding:14px;border:1px solid rgba(39,230,161,.22);background:rgba(39,230,161,.08);border-radius:14px;color:#baf7dd}.errors{margin-bottom:18px;padding:14px;border:1px solid rgba(255,95,115,.25);background:rgba(255,95,115,.08);border-radius:14px}.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--bull);box-shadow:0 0 16px var(--bull);margin-right:6px}@media(max-width:900px){.topbar{align-items:flex-start;flex-direction:column}.layout{grid-template-columns:1fr}.metrics{grid-template-columns:1fr}.settings{align-items:flex-start;flex-direction:column}.timer{text-align:left}.wrap{padding:18px 12px 40px}}
</style>
</head>
<body>
<div class="wrap">
<header class="topbar"><div class="brand"><div class="coin">₿</div><div><h1>Histórico dos Indicadores</h1><small><span class="dot"></span>BTC, ETH e 50 scores gravados no banco</small></div></div><nav class="controls"><a class="chip" href="index.php">Capa</a><a class="chip" href="indicadores.php">Indicadores</a><a class="chip active" href="historico_indicadores.php">Histórico com gráfico</a><a class="chip" href="graficos_selecionados.php">Gráficos selecionados</a><a class="chip" href="super_previsao.php">Super Previsão</a><a class="chip" href="trade_simulado.php">Trade simulado</a><a class="chip" href="coletar_indicadores.php">Coletar agora</a></nav></header>
<form class="card settings" method="post">
  <label>Salvar novo registro a cada
    <select name="save_interval_minutes" onchange="this.form.submit()">
      <?php foreach (INDICADOR_ALLOWED_SAVE_INTERVALS as $minutes): ?>
      <option value="<?= $minutes ?>" <?= $minutes === $saveIntervalMinutes ? 'selected' : '' ?>><?= $minutes === 60 ? '1 hora' : $minutes . ' minuto' . ($minutes > 1 ? 's' : '') ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="timer">Próxima gravação automática em <b id="countdown">--:--</b></div>
</form>
<?php if ($notice): ?><div class="ok"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="errors"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<section class="layout">
<aside class="card panel"><div class="muted">Escolha as linhas do gráfico</div><div class="actions"><button class="btn" type="button" id="all">Todos</button><button class="btn" type="button" id="none">Limpar</button><button class="btn" type="button" id="base">BTC + ETH</button></div><div class="checklist" id="checks"></div></aside>
<main class="card chart-card"><div class="metrics"><div class="metric"><span>Registros 24h</span><b><?= count($rows) ?></b></div><div class="metric"><span>Último BTC</span><b><?= $rows ? '$ ' . number_format((float)end($rows)['btc_price'], 2, ',', '.') : '—' ?></b></div><div class="metric"><span>Último ETH</span><b><?= $rows ? '$ ' . number_format((float)end($rows)['eth_price'], 2, ',', '.') : '—' ?></b></div></div><canvas id="chart" width="1200" height="520"></canvas><div class="notice">O gráfico mostra sempre as últimas 24 horas. Os indicadores são gravados como score numérico de -2 a +2. BTC e ETH aparecem normalizados no mesmo eixo para comparar a oscilação junto com os sinais.</div></main>
</section>
</div>
<script>
let nextSaveAt = <?= (int)$nextSaveTimestamp ?> * 1000;
let isCollecting = false;
const countdown = document.getElementById('countdown');

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
    const response = await fetch('coletar_indicadores.php?auto=1', {cache: 'no-store'});
    const data = await response.json();
    if (data.saved) {
      window.location.reload();
      return;
    }
    if (data.next_save_at) {
      nextSaveAt = Date.parse(data.next_save_at);
    } else {
      nextSaveAt = Date.now() + <?= (int)$saveIntervalSeconds ?> * 1000;
    }
  } catch (error) {
    nextSaveAt = Date.now() + 30000;
  } finally {
    isCollecting = false;
  }
}

function tickSaveTimer() {
  const remaining = nextSaveAt - Date.now();
  countdown.textContent = formatRemaining(remaining);
  if (remaining <= 0) {
    collectWhenDue();
  }
}

setInterval(tickSaveTimer, 1000);
tickSaveTimer();

const rows = <?= json_encode($chartRows, JSON_UNESCAPED_UNICODE) ?>;
const indicatorNames = <?= json_encode(array_values($indicatorNames), JSON_UNESCAPED_UNICODE) ?>;
const savedSelectedSeries = <?= json_encode(array_values($selectedChartSeries), JSON_UNESCAPED_UNICODE) ?>;
const palette = ['#f7b928','#5ca8ff','#27e6a1','#ff5f73','#f3bd4c','#c084fc','#38bdf8','#fb7185','#a3e635','#f97316'];
const savedSelectedSet = new Set(savedSelectedSeries);
const hasSavedSelection = savedSelectedSeries.length > 0;
let saveSelectionTimer = null;
const series = [
  {key:'btc_price', label:'Bitcoin', color:'#f7b928', normalize:true, checked:true},
  {key:'eth_price', label:'Ethereum', color:'#5ca8ff', normalize:true, checked:true},
  ...indicatorNames.map((name, idx) => ({key:'indicator_' + String(idx + 1).padStart(2, '0'), label:name, color:palette[(idx + 2) % palette.length], normalize:false, checked:idx < 5}))
].map(item => ({...item, checked: hasSavedSelection ? savedSelectedSet.has(item.key) : item.checked}));
const checks = document.getElementById('checks');
const canvas = document.getElementById('chart');
const ctx = canvas.getContext('2d');

function selectedSeriesKeys() {
  return series
    .filter((s, idx) => document.querySelector(`[data-idx="${idx}"]`)?.checked)
    .map(s => s.key);
}

function saveChartSelection() {
  clearTimeout(saveSelectionTimer);
  saveSelectionTimer = setTimeout(() => {
    fetch('salvar_selecao_grafico.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({selected_series: selectedSeriesKeys()}),
      cache: 'no-store'
    }).catch(() => {});
  }, 250);
}

function indicatorLegend(name) {
  const defs = [
    ['Bitcoin', 'Preço do BTC normalizado no eixo do gráfico para comparar sua oscilação com os indicadores.'],
    ['Ethereum', 'Preço do ETH normalizado no eixo do gráfico para comparar sua oscilação com BTC e os indicadores.'],
    ['EMA 9', 'Média móvel exponencial curta contra a média de 21 períodos. Mostra se o preço recente está ganhando ou perdendo força no curto prazo.'],
    ['EMA 50', 'Compara médias exponenciais de 50 e 200 períodos. EMA 50 acima da 200 sugere tendência principal positiva; abaixo sugere tendência negativa.'],
    ['EMA 200', 'Compara o preço atual com a média exponencial de 200 períodos, usada como referência de tendência longa.'],
    ['SMA 20', 'Compara médias móveis simples de 20 e 50 períodos. Ajuda a ver se o ritmo recente está acima ou abaixo da tendência curta.'],
    ['Inclinação', 'Mede a inclinação da média móvel. Média subindo indica aceleração da tendência; caindo indica perda de força.'],
    ['ADX', 'ADX mede a força da tendência; +DI e -DI indicam qual lado domina. ADX alto com +DI maior favorece alta, com -DI maior favorece baixa.'],
    ['Supertrend', 'Indicador baseado em ATR que acompanha o preço com bandas de volatilidade. Preço acima favorece regime comprador; abaixo favorece vendedor.'],
    ['Ichimoku', 'Tenkan e Kijun são linhas de equilíbrio do Ichimoku. Tenkan acima da Kijun indica impulso comprador; abaixo indica impulso vendedor.'],
    ['Donchian', 'Canal formado pela máxima e mínima recentes. Preço perto do topo mostra força; perto da base mostra fraqueza.'],
    ['RSI 14', 'Índice de Força Relativa de 14 períodos. Oscilador de momentum; acima de 70 costuma indicar sobrecompra e abaixo de 30 sobrevenda.'],
    ['RSI 7', 'RSI mais curto e sensível. Reage rápido ao momentum imediato, mas também gera mais ruído.'],
    ['MACD', 'MACD compara EMAs de 12 e 26 períodos; o histograma mostra a diferença contra a linha de sinal. Positivo indica aceleração compradora.'],
    ['Stochastic', 'Oscilador Estocástico: %K mostra onde o fechamento está dentro da faixa máxima-mínima recente; %D é a média suavizada do %K. Acima de 80 sugere sobrecompra e abaixo de 20 sobrevenda.'],
    ['Stoch RSI', 'Aplica a fórmula estocástica ao RSI, não ao preço. Mostra se o RSI está perto do topo ou da base da própria faixa recente.'],
    ['ROC', 'Rate of Change mede a variação percentual do preço contra períodos atrás. Valor positivo indica momentum de alta; negativo indica queda.'],
    ['CCI', 'Commodity Channel Index compara o preço típico com sua média. Leituras altas sugerem força; leituras baixas sugerem fraqueza.'],
    ['Williams', 'Williams %R mede o fechamento dentro da faixa máxima-mínima recente em escala de 0 a -100. Perto de 0 indica força; perto de -100 indica fraqueza.'],
    ['Momentum 10', 'Diferença entre o preço atual e o preço de 10 candles atrás. Positivo mostra avanço; negativo mostra recuo.'],
    ['PPO', 'Percentage Price Oscillator é uma versão percentual do MACD. Facilita comparar momentum entre preços diferentes.'],
    ['Volume relativo', 'Compara o volume atual com a média recente. Volume acima da média aumenta a confiança no movimento do candle.'],
    ['OBV', 'On-Balance Volume soma volume em candles de alta e subtrai em candles de baixa. Subindo sugere acumulação; caindo sugere distribuição.'],
    ['MFI', 'Money Flow Index combina preço e volume, funcionando como um RSI ponderado por volume. Acima de 80 sugere sobrecompra; abaixo de 20 sobrevenda.'],
    ['Chaikin', 'Chaikin Money Flow mede pressão de compra ou venda usando fechamento dentro do candle e volume. Positivo sugere acumulação.'],
    ['VWAP', 'Preço médio ponderado por volume. Preço acima do VWAP indica negociação acima do valor médio do período; abaixo indica fraqueza relativa.'],
    ['Accumulation', 'Linha de Acumulação/Distribuição combina local do fechamento no candle com volume. Alta na linha indica fluxo acumulador.'],
    ['Price Volume Trend', 'PVT acumula volume ponderado pela variação percentual do preço. Ajuda a confirmar tendências com participação de volume.'],
    ['Force Index', 'Force Index combina variação de preço e volume para medir a força do movimento. Positivo favorece compradores; negativo favorece vendedores.'],
    ['Taker Buy', 'Percentual de volume executado por compradores agressivos. Acima de 50% mostra mais compras a mercado; abaixo mostra mais vendas a mercado.'],
    ['Confirmação', 'Confirma se a direção do preço veio acompanhada por expansão de volume. Movimento com volume tende a ter mais validade.'],
    ['Bollinger Position', 'Mostra onde o preço está dentro das Bandas de Bollinger. Perto da banda superior indica força/sobrecompra; perto da inferior indica fraqueza/sobrevenda.'],
    ['Bollinger Bandwidth', 'Mede a largura das Bandas de Bollinger. Largura aumentando indica volatilidade em expansão; contraindo indica compressão.'],
    ['ATR', 'Average True Range mede volatilidade média. ATR maior indica candles mais amplos, mas não define direção sozinho.'],
    ['Keltner', 'Canal baseado em média móvel e ATR. Preço na parte superior sugere força; na inferior sugere fraqueza.'],
    ['Estrutura', 'Leitura de máximas e mínimas. HH/HL indica estrutura de alta; LH/LL indica estrutura de baixa.'],
    ['Choppiness', 'Mede se o mercado está tendencial ou lateral. Valor baixo sugere tendência; valor alto sugere mercado travado/choppy.'],
    ['Volatilidade Realizada', 'Volatilidade calculada pelos retornos recentes. Quando sobe, o mercado está se movendo com mais intensidade.'],
    ['Impulso do Candle', 'Compara o corpo do candle com o range total. Corpo grande mostra decisão; corpo pequeno mostra indecisão.'],
    ['Range 20', 'Mostra a posição do preço dentro da faixa dos últimos 20 candles. Topo da faixa favorece força; base favorece fraqueza.'],
    ['Alinhamento', 'Compara retornos de 3 e 12 candles. Quando ambos apontam na mesma direção, há alinhamento de curtíssimo e curto prazo.'],
    ['Order Book', 'Compara liquidez em bids e asks no livro de ofertas. Mais bids pode indicar suporte; mais asks pode indicar resistência.'],
    ['Bid/Ask', 'Spread entre melhor compra e melhor venda. Spread estreito indica execução mais eficiente; spread largo indica menor liquidez.'],
    ['Walls', 'Compara grandes ordens próximas no topo do book. Paredes de compra podem sustentar preço; paredes de venda podem limitar alta.'],
    ['Depth', 'Liquidez próxima do preço atual dentro de +/-0,25%. Mais bids favorece suporte próximo; mais asks favorece pressão vendedora.'],
    ['Funding', 'Taxa paga entre longs e shorts nos futuros perpétuos. Funding muito positivo indica longs lotados; muito negativo indica shorts lotados.'],
    ['Open Interest + Preço', 'Compara mudança no open interest com o preço. OI subindo junto da direção do preço sugere entrada de novas posições.'],
    ['Open Interest Trend', 'Mostra se a alavancagem agregada está aumentando ou diminuindo ao longo do período analisado.'],
    ['Basis', 'Diferença entre preço de marca do futuro e preço índice. Prêmio indica futuro acima do índice; desconto indica abaixo.'],
    ['Global Long/Short', 'Razão entre contas compradas e vendidas. Extremos podem indicar consenso excessivo e risco de movimento contrário.'],
    ['Top Traders', 'Razão de posições dos maiores traders. Ajuda a ver se players grandes estão mais expostos em long ou short.']
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
  series.forEach((item, idx) => {
    const label = document.createElement('label');
    label.className = 'check';
    const legend = indicatorLegend(item.label);
    label.innerHTML = `<input type="checkbox" data-idx="${idx}" ${item.checked ? 'checked' : ''}> <span>${escapeAttr(item.label)}</span> <button class="help" type="button" title="${escapeAttr(legend)}" data-legend="${escapeAttr(legend)}" aria-label="Descrição de ${escapeAttr(item.label)}">?</button>`;
    checks.appendChild(label);
  });
}

function valuesFor(s) {
  const values = rows.map(row => row[s.key]).filter(v => v !== null && !Number.isNaN(v));
  if (!s.normalize) return rows.map(row => row[s.key]);
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = Math.max(max - min, 0.000001);
  return rows.map(row => row[s.key] === null ? null : ((row[s.key] - min) / range) * 4 - 2);
}

function draw() {
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  canvas.width = Math.max(600, Math.floor(rect.width * dpr));
  canvas.height = Math.floor(520 * dpr);
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  const w = canvas.width / dpr;
  const h = canvas.height / dpr;
  ctx.clearRect(0, 0, w, h);
  ctx.fillStyle = '#0b1520';
  ctx.fillRect(0, 0, w, h);
  const pad = {l:52, r:18, t:22, b:54};
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
    ctx.fillStyle = '#8fa2b8'; ctx.font = '16px Arial'; ctx.fillText('Nenhum registro gravado ainda. Clique em "Coletar agora".', pad.l, pad.t + 40);
    return;
  }
  const active = series.filter((s, idx) => document.querySelector(`[data-idx="${idx}"]`)?.checked);
  active.forEach(s => {
    const vals = valuesFor(s);
    ctx.strokeStyle = s.color;
    ctx.lineWidth = s.normalize ? 2.5 : 1.6;
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
}

buildChecks();
checks.addEventListener('click', event => {
  if (event.target.closest('.help')) {
    event.preventDefault();
    event.stopPropagation();
  }
});
checks.addEventListener('change', () => { draw(); saveChartSelection(); });
document.getElementById('all').addEventListener('click', () => { checks.querySelectorAll('input').forEach(i => i.checked = true); draw(); saveChartSelection(); });
document.getElementById('none').addEventListener('click', () => { checks.querySelectorAll('input').forEach(i => i.checked = false); draw(); saveChartSelection(); });
document.getElementById('base').addEventListener('click', () => { checks.querySelectorAll('input').forEach((i, idx) => i.checked = idx < 2); draw(); saveChartSelection(); });
window.addEventListener('resize', draw);
draw();
</script>
<script src="cron_trade.js?v=<?= filemtime(__DIR__ . '/cron_trade.js') ?>" defer></script>
</body>
</html>
