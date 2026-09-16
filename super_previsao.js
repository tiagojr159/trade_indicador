'use strict';
(() => {
  let data = JSON.parse(document.getElementById('initialData').textContent);
  const $ = id => document.getElementById(id);
  const labels = ['Baixa', 'Lateral', 'Alta'];
  const classes = ['down', 'flat', 'up'];
  const colors = ['#ff7182', '#bdc6cc', '#3cdda5'];
  const number = (value, digits = 1) => Number(value).toLocaleString('pt-BR', {minimumFractionDigits: digits, maximumFractionDigits: digits});
  const percent = value => value == null ? '--' : `${number(value * 100)}%`;
  const signed = value => value == null ? '--' : `${value > 0 ? '+' : ''}${number(value, 2)}%`;
  const date = (time, full = false) => new Date(time * 1000).toLocaleString('pt-BR', {
    timeZone: data.timezone, ...(full ? {day: '2-digit', month: '2-digit'} : {}), hour: '2-digit', minute: '2-digit'
  });
  const escape = value => String(value).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const tips = [
    'Compara as medias exponenciais de 9 e 21 candles. A nota resume a direcao do cruzamento.',
    'Compara as medias exponenciais de 50 e 200 candles, uma leitura de tendencia mais longa.',
    'Compara o preco com a media exponencial de 200 candles.',
    'Compara as medias simples de 20 e 50 candles.',
    'Mede a variacao recente da media exponencial de 21 candles.',
    'Mede a variacao recente da media exponencial de 50 candles.',
    'ADX mede forca da tendencia; +DI e -DI indicam qual direcao predomina.',
    'Proxy do sistema: a direcao usa preco versus EMA 21; as bandas usam ATR. Nao e o Supertrend completo.',
    'Compara Tenkan e Kijun, pontos medios das faixas de 9 e 26 candles.',
    'Localiza o preco dentro da faixa entre maxima e minima de 55 candles.',
    'RSI de 14 candles compara ganhos e perdas recentes; a nota resume momentum e algumas reversoes.',
    'RSI de 7 candles acompanha momentum mais curto.',
    'Diferenca entre MACD e sua linha de sinal; resume aceleracao do momentum.',
    'Estocastico: %K localiza o fechamento na faixa de maximas e minimas; %D suaviza %K. O sistema pontua se %K esta acima ou abaixo de %D.',
    'Localiza o RSI na sua propria faixa recente; pode oscilar mais rapidamente que o RSI.',
    'Taxa percentual de mudanca do preco em 12 candles.',
    'Compara o preco tipico com sua media, ajustado pelo desvio medio de 20 candles.',
    'Localiza o fechamento na faixa recente, na escala de -100 a 0. A nota usa sua posicao em relacao a -50.',
    'Diferenca entre o preco atual e o de 10 candles atras.',
    'Diferenca percentual entre as medias exponenciais de 12 e 26 candles.',
    'Compara o volume atual com a media; a direcao da nota acompanha o candle quando ha expansao.',
    'Acumula volume com sinal positivo em altas e negativo em baixas. A nota usa sua inclinacao.',
    'Oscilador de fluxo monetario que combina preco tipico e volume em 14 candles.',
    'Mede fluxo usando posicao do fechamento na faixa do candle e volume.',
    'Compara o preco com a media ponderada pelo volume dos ultimos 50 candles.',
    'Acumula volume ponderado pela posicao do fechamento no candle. A nota usa a inclinacao da linha A/D.',
    'Acumula volume ponderado pela variacao percentual do preco.',
    'Multiplica a variacao de preco pelo volume do candle.',
    'Proporcao de volume de compras agressivas no volume total recente.',
    'Confere se a variacao recente de preco vem acompanhada de expansao do volume.',
    'Localiza o preco entre as bandas de Bollinger, baseadas em media e desvio padrao.',
    'Largura relativa das bandas de Bollinger. A nota associa expansao a direcao do candle.',
    'Amplitude verdadeira media de 14 candles como percentual do preco. A nota associa expansao a direcao do candle.',
    'Localiza o preco num canal em torno da EMA 21, com largura baseada em ATR.',
    'Compara maximas e minimas recentes para identificar estrutura ascendente ou descendente.',
    'Estima se o mercado esta mais tendencial ou lateral. A nota agrega a direcao do preco versus EMA.',
    'Dispersao dos retornos recentes. A nota associa expansao de volatilidade a direcao do candle.',
    'Compara o tamanho do corpo do candle com sua amplitude total.',
    'Localiza o preco entre maxima e minima dos ultimos 20 candles.',
    'Verifica se as variacoes de preco em 3 e 12 candles apontam a mesma direcao.',
    'Compara o valor das ordens de compra e venda no livro. Ordens podem ser canceladas.',
    'Diferenca entre melhor compra e melhor venda. No sistema, spread estreito recebe a direcao do candle, nao uma previsao propria.',
    'Compara o volume financeiro dos cinco primeiros niveis de compra e venda.',
    'Compara liquidez de compra e venda proxima do preco, na faixa de 0,25%.',
    'Taxa de financiamento dos contratos perpetuos; o sistema aplica uma leitura contraria de posicionamento.',
    'Combina mudanca nos contratos em aberto com a variacao do preco.',
    'Acompanha aumento ou reducao dos contratos em aberto.',
    'Diferenca entre preco de marcacao e indice de referencia do contrato.',
    'Razao entre contas compradas e vendidas; a nota depende dos limites usados no coletor.',
    'Razao entre posicoes compradas e vendidas dos principais traders.'
  ];

  function render() {
    const p = data.prediction, m = data.metrics, last = data.latest;
    const titles = {empty: 'Aguardando hist\u00f3rico', historical: 'Refer\u00eancia hist\u00f3rica', experimental: 'Sem sinal validado', signal: p ? `Sinal de ${labels[p.label].toLowerCase()}` : 'Sem sinal'};
    $('status').textContent = data.status === 'signal' ? 'SINAL ESTAT\u00cdSTICO' : 'AN\u00c1LISE EXPERIMENTAL';
    $('signalTitle').textContent = titles[data.status];
    $('signalTitle').className = data.status === 'signal' ? classes[p.label] : '';
    let detail = !last ? 'Nenhum registro dispon\u00edvel para comparar os indicadores.' :
      !p ? 'Ainda n\u00e3o h\u00e1 cen\u00e1rios conclu\u00eddos suficientes para este horizonte.' :
      `Leitura dos cen\u00e1rios: ${labels[p.label].toLowerCase()} em ${data.minutes} minutos. ` +
      (data.validated ? 'Consulte o teste no passado e a dispers\u00e3o dos resultados.' : 'O hist\u00f3rico ainda n\u00e3o sustenta uma antecipa\u00e7\u00e3o confi\u00e1vel.');
    if (last && !data.fresh) detail += ' Dados sem atualiza\u00e7\u00e3o recente; esta leitura n\u00e3o representa o mercado atual.';
    $('signalDetail').textContent = detail;
    $('reference').textContent = last ? `${date(last.time, true)} | BTC ${number(last.btc, 2)} USDT | Candle ${last.interval} | Horizonte at\u00e9 ${date(last.time + data.minutes * 60, true)}` : 'Aguardando coleta';
    $('frequencies').innerHTML = [2, 1, 0].map(i => `<div><b class="${classes[i]}">${p ? percent(p.frequencies[i]) : '--'}</b><span>${labels[i]}</span></div>`).join('');
    $('stack').innerHTML = p ? [2, 1, 0].map(i => `<i class="${classes[i]}" style="width:${p.frequencies[i] * 100}%"></i>`).join('') : '';
    $('neighborsSummary').textContent = p ? `${p.count} cen\u00e1rios sem sobreposi\u00e7\u00e3o | Semelhan\u00e7a m\u00e9dia ${percent(p.similarity)}` : 'Cen\u00e1rios insuficientes';
    $('accuracy').textContent = m ? percent(m.accuracy) : '--';
    $('testCount').textContent = m ? `${m.correct} de ${m.count} testes | IC 95%: ${percent(m.low)} a ${percent(m.high)}` : 'Nenhum teste eleg\u00edvel';
    $('baseline').textContent = m ? percent(m.baseline) : '--';
    $('advantage').textContent = m ? `${number(Math.abs(m.accuracy - m.baseline) * 100)} p.p. ${m.accuracy >= m.baseline ? 'acima' : 'abaixo'} da refer\u00eancia` : 'Classe predominante no passado';
    $('return').textContent = p ? signed(p.average) : '--';
    $('return').className = p ? (p.average > 0 ? 'up' : p.average < 0 ? 'down' : 'flat') : '';
    $('range').textContent = p ? `P10 a P90: ${signed(p.p10)} a ${signed(p.p90)}` : 'Sem faixa dispon\u00edvel';
    $('history').textContent = `${number(data.hours)} horas`;
    $('historyDetail').textContent = `${data.records} registros | ${data.variable} indicadores vari\u00e1veis`;
    $('chartPeriod').textContent = `24 horas at\u00e9 ${date(data.chartEnd, true)}${data.fresh ? '' : ' (refer\u00eancia hist\u00f3rica)'}`;
    $('validationBadge').textContent = data.validated ? 'Crit\u00e9rios atendidos' : 'Explorat\u00f3rio';
    $('validationText').textContent = m ? `${m.count} previs\u00f5es retrospectivas, sem usar resultados futuros no c\u00e1lculo. ${m.directional} sinais de alta/baixa${m.directionAccuracy !== null ? `, com ${percent(m.directionAccuracy)} de acerto direcional` : ''}.` : 'A base ainda n\u00e3o permite um teste temporal neste horizonte.';
    $('tests').innerHTML = data.tests.length ? data.tests.slice(-12).reverse().map(t => `<tr><td title="Resultado em ${escape(date(t.end, true))}">${date(t.time, true)}</td><td class="${classes[t.predicted]}">${labels[t.predicted]}</td><td class="${classes[t.actual]}">${labels[t.actual]}</td><td>${signed(t.return)}</td><td class="${t.predicted === t.actual ? 'up' : 'down'}">${t.predicted === t.actual ? 'Acerto' : 'Erro'}</td></tr>`).join('') : '<tr><td colspan="5">Sem testes concluidos</td></tr>';
    $('indicatorCount').textContent = last ? `${last.x.filter(v => v !== null).length}/50` : '0/50';
    $('groups').innerHTML = ['Tend\u00eancia', 'Momentum', 'Volume e fluxo', 'Volatilidade', 'Derivativos e book'].map((name, i) => {
      const values = last ? last.x.slice(i * 10, i * 10 + 10).filter(v => v !== null) : [];
      const mean = values.length ? values.reduce((a, b) => a + b, 0) / values.length : 0;
      return `<div class="group"><span>${name}</span><div class="track"><i style="left:${mean >= 0 ? 50 : 50 + mean * 25}%;width:${Math.abs(mean) * 25}%;background:${mean >= 0 ? colors[2] : colors[0]}"></i></div><strong class="${mean > 0 ? 'up' : mean < 0 ? 'down' : 'flat'}">${values.length ? number(mean) : '--'}</strong></div>`;
    }).join('');
    $('indicatorList').innerHTML = last ? last.x.map((v, i) => `<div class="indicator-row"><span><button type="button" class="help" aria-label="Sobre ${escape(last.names[i] || `Indicador ${i + 1}`)}" data-tip="${escape(tips[i] + ' A nota salva e um resumo do coletor, nao o valor bruto nem uma chance de acerto.')}" >?</button>${escape(last.names[i] || `Indicador ${i + 1}`)}</span><b class="${v > 0 ? 'up' : v < 0 ? 'down' : 'flat'}">${v == null ? '--' : number(v, 0)}</b></div>`).join('') : '';
    $('methodData').textContent = `${data.source}. ${data.samples} exemplos com resultado conhecido. ${data.excluded} registros de outro intervalo ou esquema excluidos. Limite de 3.000 registros; cada comparacao usa ate 1.500 exemplos anteriores. Horarios conforme o servidor: ${data.timezone}. ${data.database === 'unavailable' ? 'Banco indisponivel ou tabela ainda ausente; exibindo somente a base importada.' : ''}`;
    $('footerSource').textContent = `${data.source} | ${last ? date(last.time, true) : 'Sem dados'}`;
    draw();
  }

  let geometry = null;
  function draw() {
    const canvas = $('chart'), ctx = canvas.getContext('2d');
    const box = canvas.getBoundingClientRect(), width = box.width, height = box.height;
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(width * ratio); canvas.height = Math.round(height * ratio);
    ctx.scale(ratio, ratio); ctx.clearRect(0, 0, width, height);
    const pad = {left: 52, right: 12, top: 16, bottom: 30};
    const start = data.chartEnd - 86400, end = data.chartEnd;
    const series = [['btc', '#f7b928', $('showBtc').checked], ['eth', '#77b8f4', $('showEth').checked]].filter(s => s[2]).map(([key, color]) => {
      const first = data.chart.find(r => r[key] != null && r[key] > 0);
      return {key, color, values: first ? data.chart.map(r => ({time: r.time, value: r[key] == null ? null : (r[key] / first[key] - 1) * 100})) : []};
    });
    const values = series.flatMap(s => s.values.filter(v => v.value !== null).map(v => v.value));
    const minimum = Math.min(0, ...values), maximum = Math.max(0, ...values), margin = Math.max(.1, (maximum - minimum) * .12);
    const low = minimum - margin, high = maximum + margin;
    const x = time => pad.left + (time - start) / (end - start) * (width - pad.left - pad.right);
    const y = value => pad.top + (high - value) / (high - low) * (height - pad.top - pad.bottom);
    ctx.font = '10px system-ui'; ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
      const value = low + (high - low) * i / 4, yy = y(value);
      ctx.strokeStyle = '#30373b'; ctx.beginPath(); ctx.moveTo(pad.left, yy); ctx.lineTo(width - pad.right, yy); ctx.stroke();
      ctx.fillStyle = '#a3afb4'; ctx.textAlign = 'right'; ctx.fillText(`${number(value)}%`, pad.left - 8, yy + 3);
    }
    const ticks = width < 500 ? 3 : 6;
    for (let i = 0; i <= ticks; i++) {
      const time = start + (end - start) * i / ticks;
      ctx.fillStyle = '#a3afb4'; ctx.textAlign = i === 0 ? 'left' : i === ticks ? 'right' : 'center';
      ctx.fillText(date(time), x(time), height - 6);
    }
    for (const s of series) {
      ctx.strokeStyle = s.color; ctx.lineWidth = 1.8; ctx.beginPath(); let previous = null;
      for (const r of s.values) {
        if (r.value === null) { previous = null; continue; }
        if (!previous || r.time - previous.time > 600) ctx.moveTo(x(r.time), y(r.value));
        else ctx.lineTo(x(r.time), y(r.value));
        previous = r;
      }
      ctx.stroke();
    }
    const markers = [];
    if ($('showSignals').checked) {
      const btc = series.find(s => s.key === 'btc');
      for (const test of data.tests.filter(t => t.time >= start && t.time <= end)) {
        const point = btc ? btc.values.find(r => r.time === test.time) : null;
        if (!point || point.value === null) continue;
        const px = x(test.time), py = y(point.value);
        ctx.beginPath(); ctx.arc(px, py, 3.5, 0, Math.PI * 2); ctx.fillStyle = colors[test.predicted]; ctx.fill();
        ctx.strokeStyle = '#111416'; ctx.lineWidth = 1; ctx.stroke(); markers.push({x: px, y: py, test});
      }
    }
    $('chartEmpty').hidden = values.length > 0;
    $('chartEmpty').textContent = data.chart.length ? 'Selecione BTC ou ETH' : 'Sem registros neste periodo';
    geometry = {width, height, x, start, end, pad, markers};
  }
  $('chart').addEventListener('pointermove', event => {
    if (!geometry || !data.chart.length) return;
    const rect = event.currentTarget.getBoundingClientRect(), px = event.clientX - rect.left, py = event.clientY - rect.top;
    const marker = geometry.markers.find(m => Math.hypot(m.x - px, m.y - py) < 12);
    const time = geometry.start + (px - geometry.pad.left) / (geometry.width - geometry.pad.left - geometry.pad.right) * (geometry.end - geometry.start);
    let closest = data.chart[0];
    for (const row of data.chart) if (Math.abs(row.time - time) < Math.abs(closest.time - time)) closest = row;
    if (!marker && Math.abs(geometry.x(closest.time) - px) > 20) { $('chartTooltip').hidden = true; return; }
    $('chartTooltip').textContent = marker ? `${date(marker.test.time)}: ${labels[marker.test.predicted]} prevista; ${labels[marker.test.actual]} realizada (${signed(marker.test.return)})` : `${date(closest.time, true)} | BTC ${number(closest.btc, 2)}${closest.eth !== null ? ` | ETH ${number(closest.eth, 2)}` : ''}`;
    $('chartTooltip').hidden = false;
    $('chartTooltip').style.left = `${Math.max(0, Math.min(px + 12, rect.width - $('chartTooltip').offsetWidth))}px`;
    $('chartTooltip').style.top = `${Math.max(0, Math.min(py + 12, rect.height - $('chartTooltip').offsetHeight))}px`;
  });
  $('chart').addEventListener('pointerleave', () => { $('chartTooltip').hidden = true; });
  ['showBtc', 'showEth', 'showSignals'].forEach(id => $(id).addEventListener('change', draw));
  new ResizeObserver(draw).observe($('chart').parentElement);

  function showHelp(button) {
    const tip = $('helpTooltip');
    tip.textContent = button.dataset.tip; tip.hidden = false;
    const box = button.getBoundingClientRect();
    tip.style.maxWidth = `${Math.min(320, innerWidth - 24)}px`;
    tip.style.left = `${Math.max(12, Math.min(box.left, innerWidth - tip.offsetWidth - 12))}px`;
    tip.style.top = `${Math.max(12, box.bottom + tip.offsetHeight + 16 <= innerHeight ? box.bottom + 8 : box.top - tip.offsetHeight - 8)}px`;
    button.setAttribute('aria-describedby', 'helpTooltip');
  }
  document.addEventListener('pointerover', e => { const button = e.target.closest('.help'); if (button) showHelp(button); });
  document.addEventListener('focusin', e => { if (e.target.matches('.help')) showHelp(e.target); });
  document.addEventListener('click', e => { const button = e.target.closest('.help'); if (button) showHelp(button); else $('helpTooltip').hidden = true; });
  document.addEventListener('pointerout', e => { if (e.target.closest('.help')) $('helpTooltip').hidden = true; });
  document.addEventListener('focusout', e => { if (e.target.matches('.help')) $('helpTooltip').hidden = true; });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') $('helpTooltip').hidden = true; });
  document.addEventListener('scroll', () => { $('helpTooltip').hidden = true; }, true);

  let remaining = 60, busy = false;
  async function refresh() {
    if (busy) return;
    busy = true; $('refreshStatus').textContent = 'Atualizando...';
    $('filters').querySelectorAll('input,select,button').forEach(el => { el.disabled = true; });
    // FormData omits disabled controls, so read the selected values directly.
    const params = new URLSearchParams({horizonte: $('filters').querySelector('[name=horizonte]:checked').value, limiar: $('filters').querySelector('[name=limiar]').value});
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(`super_previsao.php?${params}&format=json`, {cache: 'no-store', signal: controller.signal});
      if (!response.ok) throw new Error('HTTP');
      data = await response.json(); render(); $('error').hidden = true;
      history.replaceState(null, '', `super_previsao.php?${params}`);
    } catch {
      $('error').textContent = 'Nao foi possivel atualizar. A leitura exibida e da ultima consulta concluida; tente novamente.'; $('error').hidden = false;
    } finally {
      clearTimeout(timeout); busy = false; remaining = 60;
      $('filters').querySelectorAll('input,select,button').forEach(el => { el.disabled = false; });
      $('refreshStatus').textContent = 'Atualiza em 60 s';
    }
  }
  $('filters').addEventListener('submit', e => { e.preventDefault(); refresh(); });
  $('filters').addEventListener('change', refresh);
  setInterval(() => {
    if (busy) return;
    remaining--;
    $('refreshStatus').textContent = `Atualiza em ${remaining} s`;
    if (remaining <= 0) refresh();
  }, 1000);
  render();
})();
