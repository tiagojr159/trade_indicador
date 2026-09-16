<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sistema de Acompanhamento de Indicadores de Bitcoin</title>
<style>
:root{--bg:#071019;--panel:#111d2b;--line:rgba(255,255,255,.08);--text:#eef5ff;--muted:#8fa2b8;--bull:#27e6a1;--accent:#f7b928;--blue:#5ca8ff;--radius:22px;--shadow:0 18px 50px rgba(0,0,0,.28)}
*{box-sizing:border-box}
body{margin:0;color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:radial-gradient(circle at 12% -10%,rgba(247,185,40,.12),transparent 27%),radial-gradient(circle at 92% 4%,rgba(92,168,255,.11),transparent 25%),linear-gradient(180deg,#071019 0%,#0b1520 100%);min-height:100vh}
.wrap{max-width:1180px;margin:auto;padding:28px 24px 60px;min-height:100vh;display:flex;flex-direction:column}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:68px}
.brand{display:flex;gap:14px;align-items:center}
.coin{width:50px;height:50px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(145deg,#f8cc52,#f4a914);color:#151515;font-weight:900;font-size:28px}
.brand h1{margin:0;font-size:19px}
.brand small,.muted{color:var(--muted)}
.tabs{display:flex;gap:8px;flex-wrap:wrap}
.tab{padding:10px 13px;border:1px solid var(--line);border-radius:12px;text-decoration:none;color:var(--muted);font-size:13px;font-weight:800;background:rgba(255,255,255,.025)}
.tab.active{background:var(--accent);color:#19140a;border-color:transparent}
.cover{display:grid;grid-template-columns:1.05fr .95fr;gap:28px;align-items:center;flex:1}
.title{font-size:clamp(38px,6vw,74px);line-height:.98;margin:0 0 20px;font-weight:950}
.lead{font-size:18px;line-height:1.65;color:#b8c6d8;max-width:620px;margin:0 0 28px}
.actions{display:flex;gap:12px;flex-wrap:wrap}
.button{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 16px;border-radius:12px;border:1px solid var(--line);text-decoration:none;color:var(--text);font-weight:900;background:rgba(255,255,255,.04)}
.button.primary{background:var(--accent);color:#19140a;border-color:transparent}
.panel{border:1px solid var(--line);border-radius:var(--radius);background:linear-gradient(180deg,rgba(19,33,48,.92),rgba(12,24,36,.94));box-shadow:var(--shadow);padding:24px}
.market{display:grid;gap:13px}
.metric{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:16px;border-radius:16px;background:rgba(255,255,255,.035)}
.metric span{color:var(--muted);font-size:12px;text-transform:uppercase;font-weight:800}
.metric b{font-size:22px}
.pulse{height:180px;border-radius:18px;background:linear-gradient(135deg,rgba(247,185,40,.18),rgba(92,168,255,.12));position:relative;overflow:hidden;margin-bottom:18px}
.pulse:before{content:"";position:absolute;inset:38px 24px;border-bottom:4px solid rgba(247,185,40,.75);border-left:4px solid rgba(39,230,161,.72);transform:skewX(-18deg)}
.pulse:after{content:"BTC";position:absolute;right:24px;bottom:18px;font-size:48px;font-weight:950;color:rgba(255,255,255,.12)}
.footer{margin-top:48px;color:var(--muted);font-size:12px}
@media(max-width:850px){.wrap{padding:18px 12px 40px}.topbar{align-items:flex-start;flex-direction:column;margin-bottom:38px}.cover{grid-template-columns:1fr}.title{font-size:40px}}
</style>
</head>
<body>
<div class="wrap">
<header class="topbar">
  <div class="brand">
    <div class="coin">B</div>
    <div>
      <h1>Sistema Bitcoin</h1>
      <small>Acompanhamento de indicadores</small>
    </div>
  </div>
  <nav class="tabs">
    <a class="tab active" href="index.php">Capa</a>
    <a class="tab" href="indicadores.php">Indicadores</a>
    <a class="tab" href="historico_indicadores.php">Histórico com gráfico</a>
    <a class="tab" href="graficos_selecionados.php">Gráficos selecionados</a>
    <a class="tab" href="super_previsao.php">Super Previsão</a>
    <a class="tab" href="trade_simulado.php">Trade simulado</a>
  </nav>
</header>
<main class="cover">
  <section>
    <h2 class="title">Sistema de acompanhamento de indicadores de Bitcoin</h2>
    <p class="lead">A entrada agora carrega rápido e deixa os cálculos pesados separados. Use as abas no topo para abrir a tela dos indicadores em tempo real ou consultar o histórico com gráfico.</p>
    <div class="actions">
      <a class="button primary" href="indicadores.php">Abrir indicadores</a>
      <a class="button" href="historico_indicadores.php">Ver histórico</a>
      <a class="button" href="graficos_selecionados.php">Ver gráficos selecionados</a>
    </div>
  </section>
  <aside class="panel">
    <div class="pulse"></div>
    <div class="market">
      <div class="metric"><span>Tela inicial</span><b>rápida</b></div>
      <div class="metric"><span>Indicadores</span><b>50 sinais</b></div>
      <div class="metric"><span>Histórico</span><b>gráfico</b></div>
    </div>
  </aside>
</main>
<footer class="footer">Os dados em tempo real só são buscados quando a aba Indicadores é aberta.</footer>
</div>
<script src="cron_trade.js?v=<?= filemtime(__DIR__ . '/cron_trade.js') ?>" defer></script>
</body>
</html>
