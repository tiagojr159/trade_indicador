<?php
declare(strict_types=1);
require_once __DIR__ . '/previsao_dados.php';
require_once __DIR__ . '/trade_signal_state.php';
$minutes = filter_input(INPUT_GET, 'horizonte', FILTER_VALIDATE_INT) ?: 5;
if (!in_array($minutes, [5, 15, 30, 60], true)) {
    $minutes = 15;
}
$threshold = (float)($_GET['limiar'] ?? .1);
if (!in_array($threshold, [.05, .1, .2], true)) {
    $threshold = .1;
}
$data = spData($minutes, $threshold);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Super Previs&#227;o | Sistema Bitcoin</title>
  <link rel="stylesheet" href="super_previsao.css?v=<?= filemtime(__DIR__ . '/super_previsao.css') ?>">
  <script src="super_previsao.js?v=<?= filemtime(__DIR__ . '/super_previsao.js') ?>" defer></script>
  <script src="cron_trade.js?v=<?= filemtime(__DIR__ . '/cron_trade.js') ?>" defer></script>
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <div class="brand"><a class="coin" href="index.php" aria-label="Sistema Bitcoin">B</a><div><h1>Super Previs&#227;o</h1><small>Bitcoin / USDT</small></div></div>
    <nav aria-label="Principal"><a href="index.php">Capa</a><a href="indicadores.php">Indicadores</a><a href="historico_indicadores.php">Hist&#243;rico com gr&#225;fico</a><a href="graficos_selecionados.php">Gr&#225;ficos selecionados</a><a href="super_previsao.php" aria-current="page">Super Previs&#227;o</a><a href="trade_simulado.php">Trade simulado</a></nav>
  </header>
  <main>
    <form id="filters" class="toolbar" method="get">
      <fieldset><legend>Anteced&#234;ncia</legend><div class="segments">
      <?php foreach ([5, 15, 30, 60] as $value): ?>
        <label><input type="radio" name="horizonte" value="<?= $value ?>" <?= $minutes === $value ? 'checked' : '' ?>><span><?= $value ?> min</span></label>
      <?php endforeach; ?>
      </div></fieldset>
      <label class="threshold">Movimento m&#237;nimo <select name="limiar" aria-label="Movimento m&#237;nimo">
        <?php foreach ([.05, .1, .2] as $value): ?><option value="<?= $value ?>" <?= $threshold === $value ? 'selected' : '' ?>><?= number_format($value, 2, ',', '.') ?>%</option><?php endforeach; ?>
      </select></label>
      <div class="refresh"><span id="refreshStatus" role="status">Atualiza em 60 s</span><button type="submit" class="icon-button" title="Atualizar dados" aria-label="Atualizar dados">&#8635;</button></div>
    </form>
    <div id="error" role="alert" hidden></div>
    <section class="signal-section" aria-labelledby="signalTitle">
      <div class="signal-copy"><span class="eyebrow" id="status">ANALISANDO</span><h2 id="signalTitle">Previs&#227;o agora</h2><div id="forecastCard" class="forecast-card"><div><span class="forecast-kicker">Bitcoin nos pr&#243;ximos minutos</span><strong id="forecastLabel">Aguardando</strong><small id="forecastWindow"></small></div><div class="forecast-side"><span id="forecastStrength" class="strength">--</span><div class="forecast-meter" aria-hidden="true"><i id="forecastBar"></i></div></div></div><p id="signalDetail"></p><div class="meta" id="reference"></div></div>
      <div class="distribution"><div class="section-label">Resultados em cen&#225;rios semelhantes <button type="button" class="help" data-tip="Frequ&#234;ncias ponderadas, com regulariza&#231;&#227;o no modelo adaptativo, em at&#233; 25 cen&#225;rios passados semelhantes. N&#227;o s&#227;o probabilidades calibradas de um movimento futuro." aria-label="Sobre as frequ&#234;ncias">?</button></div><div id="frequencies" class="frequencies"></div><div class="stack" id="stack" aria-hidden="true"></div><small id="neighborsSummary"></small></div>
    </section>
    <section class="metrics" aria-label="Valida&#231;&#227;o">
      <div><span>Acerto fora da amostra <button type="button" class="help" data-tip="Em cada teste, apenas resultados anteriores ao instante da previs&#227;o podem ser usados. Os per&#237;odos avaliados n&#227;o se sobrep&#245;em. Acertos incluem alta, baixa e lateral." aria-label="Sobre o acerto">?</button></span><b id="accuracy">--</b><small id="testCount"></small></div>
      <div><span>Refer&#234;ncia simples <button type="button" class="help" data-tip="Acerto de prever sempre a classe mais frequente no passado dispon&#237;vel de cada teste. Serve como compara&#231;&#227;o para o modelo." aria-label="Sobre a refer&#234;ncia">?</button></span><b id="baseline">--</b><small id="advantage"></small></div>
      <div><span>Varia&#231;&#227;o m&#233;dia posterior <button type="button" class="help" data-tip="Retorno m&#233;dio do BTC nos cen&#225;rios semelhantes ap&#243;s o horizonte escolhido. A faixa P10&#8211;P90 descreve o passado; n&#227;o &#233; um alvo de pre&#231;o ou intervalo de previs&#227;o." aria-label="Sobre a varia&#231;&#227;o">?</button></span><b id="return">--</b><small id="range"></small></div>
      <div><span>Hist&#243;rico analisado</span><b id="history">--</b><small id="historyDetail"></small></div>
    </section>
    <section class="chart-section" aria-labelledby="chartTitle">
      <div class="section-head"><div><h2 id="chartTitle">Mercado e previs&#245;es anteriores</h2><small id="chartPeriod"></small></div><div class="chart-controls"><label><input id="showBtc" type="checkbox" checked><span class="dot btc"></span> BTC</label><label><input id="showEth" type="checkbox" checked><span class="dot eth"></span> ETH</label><label><input id="showSignals" type="checkbox" checked> Previs&#245;es testadas</label></div></div>
      <div class="plot"><canvas id="chart" role="img" aria-label="Varia&#231;&#227;o percentual de BTC e ETH nas &#250;ltimas 24 horas, com previs&#245;es calculadas apenas a partir do passado"></canvas><div id="chartTooltip" hidden></div><div id="chartEmpty" hidden>Sem registros neste per&#237;odo</div></div>
      <div class="chart-caption"><span>Varia&#231;&#227;o desde o primeiro pre&#231;o dispon&#237;vel na janela</span><span><i class="dot up"></i> Alta <i class="dot down"></i> Baixa <i class="dot flat"></i> Lateral</span></div>
    </section>
    <section class="details-grid">
      <div class="validation"><div class="section-head"><h2>Teste no passado</h2><span id="validationBadge" class="badge"></span></div><p id="validationText"></p><div class="table-scroll"><table><thead><tr><th>Previs&#227;o em</th><th>Previsto</th><th>Realizado</th><th>BTC</th><th>Resultado</th></tr></thead><tbody id="tests"></tbody></table></div></div>
      <div class="indicators"><div class="section-head"><h2>Indicadores na refer&#234;ncia</h2><span id="indicatorCount" class="badge"></span></div><div id="groups"></div><details><summary>Todos os 50 indicadores</summary><div id="indicatorList"></div></details></div>
    </section>
    <details class="method"><summary>Critérios, dados e limites</summary><p id="methodData"></p><p>O motor usa os mesmos 50 indicadores da tela Indicadores, separados em cinco blocos. O modelo adaptativo aprende pesos apenas com resultados anteriores, reduz sinais repetidos e ignora notas constantes. Acrescenta a variação passada do BTC em 5, 15 e 60 minutos e a mudança dos blocos em 5 minutos. ETH permanece como contexto do gráfico.</p><p>Alta e baixa usam o movimento mínimo escolhido; lateral é o intervalo entre esses limites. Até 25 cenários sem sobreposição são ponderados por semelhança e recência, com redução de frequências extremas em amostras pequenas. São necessários 40 exemplos concluídos, 5 cenários comparáveis e 40 indicadores preenchidos. O modelo adaptativo substitui o anterior somente quando erra menos em pelo menos 12 avaliações já encerradas; essa escolha é refeita ao longo do tempo.</p><p>Uma entrada exige coleta recente, pelo menos 8 cenários efetivos, frequência direcional de 50%, vantagem de 12 pontos sobre as outras classes e movimento estimado superior aos custos, à incerteza e à margem adicional de 0,03%. Exige também pelo menos 12 sinais anteriores elegíveis e limite inferior positivo para a média de seus resultados líquidos. As frequências exibidas não são probabilidades calibradas.</p><p>A tela Trade Simulado apresenta o resultado após taxa estimada de 0,10% e slippage de 0,02% por lado, exposição de 25% e saídas por prazo ou risco. A classificação de mercado e a decisão de operar são diferentes: alta ou baixa pode resultar em aguardar. O teste é retrospectivo e exploratório; dependência temporal, custos diferentes e saltos entre coletas podem alterar o resultado. Não há garantia de lucro.</p><p>Referências: <a href="https://scikit-learn.org/stable/modules/generated/sklearn.model_selection.TimeSeriesSplit.html" target="_blank" rel="noopener noreferrer">validação temporal</a> e <a href="https://www.binance.com/en/square/post/43182" target="_blank" rel="noopener noreferrer">custos e limites de backtests</a>.</p></details>
  </main>
  <footer>Super Previs&#227;o <span id="footerSource"></span></footer>
</div>
<div id="helpTooltip" role="tooltip" hidden></div>
<script id="initialData" type="application/json"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</body>
</html>
