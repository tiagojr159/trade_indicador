<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$rows = [];
$indicatorNames = [];
$error = null;

try {
    $pdo = appPdo();
    $schema = file_get_contents(__DIR__ . '/criar_tabela_indicadores.sql');
    if ($schema !== false) {
        $pdo->exec($schema);
    }

    $stmt = $pdo->query('SELECT * FROM indicador_historico ORDER BY created_at DESC LIMIT 240');
    $rows = array_reverse($stmt->fetchAll());
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
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Histórico dos Indicadores</title>
<style>
:root{--bg:#071019;--panel:#111d2b;--line:rgba(255,255,255,.08);--text:#eef5ff;--muted:#8fa2b8;--bull:#27e6a1;--bear:#ff5f73;--neutral:#f3bd4c;--accent:#f7b928;--blue:#5ca8ff;--radius:22px;--shadow:0 18px 50px rgba(0,0,0,.28)}*{box-sizing:border-box}body{margin:0;color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:radial-gradient(circle at 12% -10%,rgba(247,185,40,.12),transparent 27%),radial-gradient(circle at 92% 4%,rgba(92,168,255,.11),transparent 25%),linear-gradient(180deg,#071019 0%,#0b1520 100%);min-height:100vh}.wrap{max-width:1600px;margin:auto;padding:28px 24px 60px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:24px}.brand{display:flex;gap:14px;align-items:center}.coin{width:50px;height:50px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(145deg,#f8cc52,#f4a914);color:#151515;font-weight:900;font-size:28px}.brand h1{margin:0;font-size:22px}.brand small,.muted{color:var(--muted)}.controls{display:flex;gap:8px;flex-wrap:wrap}.chip{padding:9px 12px;border:1px solid var(--line);border-radius:12px;text-decoration:none;color:var(--muted);font-size:13px;font-weight:800;background:rgba(255,255,255,.025)}.chip.active{background:var(--accent);color:#19140a;border-color:transparent}.card{background:linear-gradient(180deg,rgba(19,33,48,.92),rgba(12,24,36,.94));border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}.layout{display:grid;grid-template-columns:320px 1fr;gap:18px}.panel{padding:18px}.chart-card{padding:18px;min-height:620px}.checklist{display:grid;gap:8px;max-height:520px;overflow:auto;padding-right:6px}.check{display:flex;align-items:center;gap:9px;padding:9px;border-radius:12px;background:rgba(255,255,255,.035);font-size:12px;color:var(--muted)}.check input{accent-color:var(--accent)}.actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.btn{border:1px solid var(--line);background:rgba(255,255,255,.045);color:var(--text);border-radius:12px;padding:9px 11px;font-weight:800;cursor:pointer}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}.metric{padding:13px;border-radius:14px;background:rgba(255,255,255,.035)}.metric span{display:block;color:var(--muted);font-size:11px;text-transform:uppercase}.metric b{font-size:18px}canvas{width:100%;height:520px;display:block}.notice{margin-top:14px;padding:14px;border:1px solid rgba(243,189,76,.18);background:rgba(243,189,76,.055);border-radius:14px;color:#d7c69e;font-size:12px;line-height:1.5}.errors{margin-bottom:18px;padding:14px;border:1px solid rgba(255,95,115,.25);background:rgba(255,95,115,.08);border-radius:14px}.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--bull);box-shadow:0 0 16px var(--bull);margin-right:6px}@media(max-width:900px){.topbar{align-items:flex-start;flex-direction:column}.layout{grid-template-columns:1fr}.metrics{grid-template-columns:1fr}.wrap{padding:18px 12px 40px}}
</style>
</head>
<body>
<div class="wrap">
<header class="topbar"><div class="brand"><div class="coin">₿</div><div><h1>Histórico dos Indicadores</h1><small><span class="dot"></span>BTC, ETH e 50 scores gravados no banco</small></div></div><nav class="controls"><a class="chip" href="index.php">Capa</a><a class="chip" href="indicadores.php">Indicadores</a><a class="chip active" href="historico_indicadores.php">Histórico com gráfico</a><a class="chip" href="coletar_indicadores.php">Coletar agora</a></nav></header>
<?php if ($error): ?><div class="errors"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<section class="layout">
<aside class="card panel"><div class="muted">Escolha as linhas do gráfico</div><div class="actions"><button class="btn" type="button" id="all">Todos</button><button class="btn" type="button" id="none">Limpar</button><button class="btn" type="button" id="base">BTC + ETH</button></div><div class="checklist" id="checks"></div></aside>
<main class="card chart-card"><div class="metrics"><div class="metric"><span>Registros</span><b><?= count($rows) ?></b></div><div class="metric"><span>Último BTC</span><b><?= $rows ? '$ ' . number_format((float)end($rows)['btc_price'], 2, ',', '.') : '—' ?></b></div><div class="metric"><span>Último ETH</span><b><?= $rows ? '$ ' . number_format((float)end($rows)['eth_price'], 2, ',', '.') : '—' ?></b></div></div><canvas id="chart" width="1200" height="520"></canvas><div class="notice">Os indicadores são gravados como score numérico de -2 a +2. BTC e ETH aparecem normalizados no mesmo eixo para comparar a oscilação junto com os sinais.</div></main>
</section>
</div>
<script>
const rows = <?= json_encode($chartRows, JSON_UNESCAPED_UNICODE) ?>;
const indicatorNames = <?= json_encode(array_values($indicatorNames), JSON_UNESCAPED_UNICODE) ?>;
const palette = ['#f7b928','#5ca8ff','#27e6a1','#ff5f73','#f3bd4c','#c084fc','#38bdf8','#fb7185','#a3e635','#f97316'];
const series = [
  {key:'btc_price', label:'Bitcoin', color:'#f7b928', normalize:true, checked:true},
  {key:'eth_price', label:'Ethereum', color:'#5ca8ff', normalize:true, checked:true},
  ...indicatorNames.map((name, idx) => ({key:'indicator_' + String(idx + 1).padStart(2, '0'), label:name, color:palette[(idx + 2) % palette.length], normalize:false, checked:idx < 5}))
];
const checks = document.getElementById('checks');
const canvas = document.getElementById('chart');
const ctx = canvas.getContext('2d');

function buildChecks() {
  checks.innerHTML = '';
  series.forEach((item, idx) => {
    const label = document.createElement('label');
    label.className = 'check';
    label.innerHTML = `<input type="checkbox" data-idx="${idx}" ${item.checked ? 'checked' : ''}> <span>${item.label}</span>`;
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
checks.addEventListener('change', draw);
document.getElementById('all').addEventListener('click', () => { checks.querySelectorAll('input').forEach(i => i.checked = true); draw(); });
document.getElementById('none').addEventListener('click', () => { checks.querySelectorAll('input').forEach(i => i.checked = false); draw(); });
document.getElementById('base').addEventListener('click', () => { checks.querySelectorAll('input').forEach((i, idx) => i.checked = idx < 2); draw(); });
window.addEventListener('resize', draw);
draw();
</script>
</body>
</html>
