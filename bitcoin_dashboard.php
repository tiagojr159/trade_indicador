<?php
/**
 * Bitcoin Pulse — Dashboard técnico BTC/USDT em um único arquivo PHP.
 * Fonte de dados: endpoints públicos Binance Spot + USD-M Futures.
 * Uso: coloque este arquivo no htdocs do XAMPP e acesse pelo navegador.
 * Requisitos: PHP 8+ com cURL (há fallback para file_get_contents).
 */

declare(strict_types=1);

date_default_timezone_set('America/Recife');

$allowedIntervals = ['15m', '30m', '1h', '2h', '4h'];
$interval = $_GET['interval'] ?? '1h';
if (!in_array($interval, $allowedIntervals, true)) {
    $interval = '1h';
}

function httpJson(string $url, int $timeout = 8): ?array
{
    $body = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'BitcoinPulse/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            $body = false;
        }
    }

    if ($body === false && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => "User-Agent: BitcoinPulse/1.0\r\nAccept: application/json\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
    }

    if ($body === false) return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function emaSeries(array $values, int $period): array
{
    $n = count($values);
    if ($n === 0) return [];
    $k = 2 / ($period + 1);
    $ema = [(float)$values[0]];
    for ($i = 1; $i < $n; $i++) {
        $ema[$i] = ((float)$values[$i] * $k) + ($ema[$i - 1] * (1 - $k));
    }
    return $ema;
}

function sma(array $values, int $period): ?float
{
    if (count($values) < $period) return null;
    $slice = array_slice($values, -$period);
    return array_sum($slice) / $period;
}

function stdDev(array $values): float
{
    $n = count($values);
    if ($n === 0) return 0.0;
    $mean = array_sum($values) / $n;
    $sum = 0.0;
    foreach ($values as $v) $sum += (($v - $mean) ** 2);
    return sqrt($sum / $n);
}

function rsiSeries(array $closes, int $period = 14): array
{
    $n = count($closes);
    $out = array_fill(0, $n, null);
    if ($n <= $period) return $out;

    $gain = 0.0;
    $loss = 0.0;
    for ($i = 1; $i <= $period; $i++) {
        $d = $closes[$i] - $closes[$i - 1];
        if ($d >= 0) $gain += $d; else $loss += abs($d);
    }
    $avgGain = $gain / $period;
    $avgLoss = $loss / $period;
    $out[$period] = $avgLoss == 0.0 ? 100.0 : 100 - (100 / (1 + ($avgGain / $avgLoss)));

    for ($i = $period + 1; $i < $n; $i++) {
        $d = $closes[$i] - $closes[$i - 1];
        $g = max($d, 0);
        $l = max(-$d, 0);
        $avgGain = (($avgGain * ($period - 1)) + $g) / $period;
        $avgLoss = (($avgLoss * ($period - 1)) + $l) / $period;
        $out[$i] = $avgLoss == 0.0 ? 100.0 : 100 - (100 / (1 + ($avgGain / $avgLoss)));
    }
    return $out;
}

function lastValue(array $arr): ?float
{
    if (!$arr) return null;
    $v = end($arr);
    return is_numeric($v) ? (float)$v : null;
}

function pct(float $a, float $b): float
{
    return $b == 0.0 ? 0.0 : (($a - $b) / $b) * 100;
}

function fmtMoney(?float $v): string
{
    if ($v === null) return '—';
    return '$ ' . number_format($v, 2, ',', '.');
}

function fmtNum(?float $v, int $dec = 2): string
{
    return $v === null ? '—' : number_format($v, $dec, ',', '.');
}

function signalMeta(int $score): array
{
    if ($score >= 6) return ['ALTA', 'bull'];
    if ($score <= -6) return ['BAIXA', 'bear'];
    return ['NEUTRO', 'neutral'];
}

function clamp(int $v, int $min, int $max): int
{
    return max($min, min($max, $v));
}

$errors = [];
$symbol = 'BTCUSDT';

// 1) Candles spot: 300 permite EMA 200 com sobra.
$klines = httpJson("https://api.binance.com/api/v3/klines?symbol={$symbol}&interval={$interval}&limit=300");
$ticker = httpJson("https://api.binance.com/api/v3/ticker/24hr?symbol={$symbol}");
$depth = httpJson("https://api.binance.com/api/v3/depth?symbol={$symbol}&limit=20");

// 2) Derivativos USD-M: funding e open interest histórico.
$futuresPeriod = in_array($interval, ['15m', '30m', '1h', '2h', '4h'], true) ? $interval : '1h';
$premium = httpJson("https://fapi.binance.com/fapi/v1/premiumIndex?symbol={$symbol}");
$oiHist = httpJson("https://fapi.binance.com/futures/data/openInterestHist?symbol={$symbol}&period={$futuresPeriod}&limit=3");

if (!$klines || count($klines) < 210) {
    $errors[] = 'Não foi possível obter candles suficientes da Binance neste momento.';
    $klines = [];
}

$closes = $highs = $lows = $volumes = $takerBuys = $times = [];
foreach ($klines as $k) {
    if (!is_array($k) || count($k) < 11) continue;
    $times[] = (int)$k[0];
    $closes[] = (float)$k[4];
    $highs[] = (float)$k[2];
    $lows[] = (float)$k[3];
    $volumes[] = (float)$k[5];
    $takerBuys[] = (float)$k[9];
}

$currentPrice = isset($ticker['lastPrice']) ? (float)$ticker['lastPrice'] : lastValue($closes);
$change24h = isset($ticker['priceChangePercent']) ? (float)$ticker['priceChangePercent'] : null;
$high24h = isset($ticker['highPrice']) ? (float)$ticker['highPrice'] : null;
$low24h = isset($ticker['lowPrice']) ? (float)$ticker['lowPrice'] : null;
$quoteVolume24h = isset($ticker['quoteVolume']) ? (float)$ticker['quoteVolume'] : null;

$indicators = [];
$totalScore = 0;

if ($closes) {
    $lastIdx = count($closes) - 1;

    // 1. RSI 14
    $rsi = rsiSeries($closes, 14);
    $rsiNow = $rsi[$lastIdx] ?? null;
    $rsiPrev = $rsi[$lastIdx - 1] ?? $rsiNow;
    $score = 0;
    $detail = 'Momentum equilibrado.';
    if ($rsiNow !== null) {
        if ($rsiNow < 30 && $rsiNow > (float)$rsiPrev) { $score = 8; $detail = 'Sobrevendido e reagindo; possível recuperação.'; }
        elseif ($rsiNow > 70 && $rsiNow < (float)$rsiPrev) { $score = -8; $detail = 'Sobrecomprado e perdendo força.'; }
        elseif ($rsiNow >= 55) { $score = 6; $detail = 'Momentum comprador acima da zona neutra.'; }
        elseif ($rsiNow <= 45) { $score = -6; $detail = 'Momentum vendedor abaixo da zona neutra.'; }
    }
    $indicators[] = ['name'=>'RSI (14)','value'=>fmtNum($rsiNow,1),'score'=>$score,'detail'=>$detail,'icon'=>'◎'];

    // 2. EMA 9 x 21
    $ema9 = emaSeries($closes, 9); $ema21 = emaSeries($closes, 21);
    $e9 = lastValue($ema9); $e21 = lastValue($ema21);
    $score = ($e9 !== null && $e21 !== null) ? ($e9 > $e21 ? 8 : -8) : 0;
    $indicators[] = ['name'=>'EMA 9 × EMA 21','value'=>fmtNum($e9,0).' / '.fmtNum($e21,0),'score'=>$score,'detail'=>$score>0?'Curta acima da média 21: viés comprador.':'Curta abaixo da média 21: viés vendedor.','icon'=>'↗'];

    // 3. EMA 50 x 200
    $ema50 = emaSeries($closes, 50); $ema200 = emaSeries($closes, 200);
    $e50 = lastValue($ema50); $e200 = lastValue($ema200);
    $score = ($e50 !== null && $e200 !== null) ? ($e50 > $e200 ? 10 : -10) : 0;
    $indicators[] = ['name'=>'Tendência EMA 50 × 200','value'=>fmtNum($e50,0).' / '.fmtNum($e200,0),'score'=>$score,'detail'=>$score>0?'Estrutura de tendência principal positiva.':'Estrutura de tendência principal negativa.','icon'=>'⌁'];

    // 4. MACD 12/26/9
    $ema12 = emaSeries($closes, 12); $ema26 = emaSeries($closes, 26);
    $macd = [];
    foreach ($closes as $i => $_) $macd[] = ($ema12[$i] ?? 0) - ($ema26[$i] ?? 0);
    $macdSignal = emaSeries($macd, 9);
    $macdNow = lastValue($macd); $sigNow = lastValue($macdSignal);
    $hist = ($macdNow ?? 0) - ($sigNow ?? 0);
    $score = $hist > 0 ? 8 : ($hist < 0 ? -8 : 0);
    $indicators[] = ['name'=>'MACD','value'=>fmtNum($hist,2),'score'=>$score,'detail'=>$score>0?'Histograma positivo: aceleração compradora.':'Histograma negativo: aceleração vendedora.','icon'=>'〽'];

    // 5. Bollinger 20,2
    $slice20 = array_slice($closes, -20);
    $bbMid = array_sum($slice20) / max(count($slice20),1);
    $sd = stdDev($slice20);
    $bbUpper = $bbMid + (2*$sd); $bbLower = $bbMid - (2*$sd);
    $pos = ($bbUpper-$bbLower) != 0 ? (($currentPrice-$bbLower)/($bbUpper-$bbLower))*100 : 50;
    if ($currentPrice > $bbUpper) { $score = 4; $detail='Preço acima da banda superior: força, mas mercado esticado.'; }
    elseif ($currentPrice > $bbMid) { $score = 6; $detail='Preço na metade superior das bandas.'; }
    elseif ($currentPrice < $bbLower) { $score = -4; $detail='Preço abaixo da banda inferior: fraqueza, porém esticado.'; }
    else { $score = -6; $detail='Preço na metade inferior das bandas.'; }
    $indicators[] = ['name'=>'Bandas de Bollinger','value'=>fmtNum($pos,0).'% da faixa','score'=>$score,'detail'=>$detail,'icon'=>'◉'];

    // 6. Volume relativo + direção do candle
    $avgVol20 = sma(array_slice($volumes, 0, -1), 20) ?? sma($volumes,20) ?? 0;
    $vNow = lastValue($volumes) ?? 0;
    $volRatio = $avgVol20 > 0 ? $vNow/$avgVol20 : 1;
    $lastMove = $closes[$lastIdx] - $closes[max(0,$lastIdx-1)];
    $score = 0;
    if ($volRatio >= 1.2) $score = $lastMove >= 0 ? 8 : -8;
    elseif ($volRatio >= 0.9) $score = $lastMove >= 0 ? 3 : -3;
    $indicators[] = ['name'=>'Volume relativo','value'=>fmtNum($volRatio,2).'×','score'=>$score,'detail'=>$volRatio>=1.2?'Movimento atual está confirmado por volume acima da média.':'Volume sem expansão forte.','icon'=>'▥'];

    // 7. Taker buy ratio (média últimos 5 candles)
    $tv = array_slice($volumes,-5); $tb = array_slice($takerBuys,-5);
    $sumV = array_sum($tv); $sumTB = array_sum($tb);
    $takerRatio = $sumV > 0 ? $sumTB/$sumV : 0.5;
    $score = $takerRatio > 0.53 ? 8 : ($takerRatio < 0.47 ? -8 : 0);
    $indicators[] = ['name'=>'Taker Buy / Sell','value'=>fmtNum($takerRatio*100,1).'% compras','score'=>$score,'detail'=>$score>0?'Compradores agressivos dominam as execuções recentes.':($score<0?'Vendedores agressivos dominam as execuções recentes.':'Fluxo agressor equilibrado.'),'icon'=>'⇄'];

    // 8. Order book imbalance top 20
    $bidNotional = $askNotional = 0.0;
    if (isset($depth['bids'],$depth['asks'])) {
        foreach ($depth['bids'] as $row) $bidNotional += ((float)$row[0]*(float)$row[1]);
        foreach ($depth['asks'] as $row) $askNotional += ((float)$row[0]*(float)$row[1]);
    }
    $bookRatio = ($bidNotional+$askNotional)>0 ? $bidNotional/($bidNotional+$askNotional) : 0.5;
    $score = $bookRatio > 0.55 ? 7 : ($bookRatio < 0.45 ? -7 : 0);
    $indicators[] = ['name'=>'Order Book (Top 20)','value'=>fmtNum($bookRatio*100,1).'% bids','score'=>$score,'detail'=>$score>0?'Maior liquidez compradora próxima do preço.':($score<0?'Maior liquidez vendedora próxima do preço.':'Book equilibrado no recorte atual.'),'icon'=>'≋'];

    // 9. Funding — interpretação contrária quando extremo
    $funding = isset($premium['lastFundingRate']) ? (float)$premium['lastFundingRate'] : null;
    $fundingPct = $funding !== null ? $funding*100 : null;
    $score = 0; $detail='Funding próximo do equilíbrio.';
    if ($funding !== null) {
        if ($funding > 0.0005) { $score=-6; $detail='Funding muito positivo: longs mais congestionados; risco de correção.'; }
        elseif ($funding < -0.0003) { $score=6; $detail='Funding negativo: shorts congestionados; favorece risco de squeeze.'; }
        elseif ($funding > 0) { $score=1; $detail='Leve predominância de longs, ainda sem extremo.'; }
        elseif ($funding < 0) { $score=-1; $detail='Leve predominância de shorts, ainda sem extremo.'; }
    }
    $indicators[] = ['name'=>'Funding Rate','value'=>fmtNum($fundingPct,4).'%','score'=>$score,'detail'=>$detail,'icon'=>'ƒ'];

    // 10. Open Interest combinado com mudança do preço
    $oiChange = null;
    if (is_array($oiHist) && count($oiHist) >= 2) {
        $a = $oiHist[count($oiHist)-2]; $b = $oiHist[count($oiHist)-1];
        $old = isset($a['sumOpenInterestValue']) ? (float)$a['sumOpenInterestValue'] : (float)($a['sumOpenInterest'] ?? 0);
        $new = isset($b['sumOpenInterestValue']) ? (float)$b['sumOpenInterestValue'] : (float)($b['sumOpenInterest'] ?? 0);
        if ($old > 0) $oiChange = pct($new,$old);
    }
    $pricePeriodChange = count($closes)>=2 ? pct($closes[$lastIdx],$closes[$lastIdx-1]) : 0;
    $score = 0; $detail='Open interest sem expansão relevante.';
    if ($oiChange !== null) {
        if ($oiChange > 0.5 && $pricePeriodChange > 0) { $score=9; $detail='Preço e OI subindo juntos: entrada de posições acompanha a alta.'; }
        elseif ($oiChange > 0.5 && $pricePeriodChange < 0) { $score=-9; $detail='Preço caindo com OI crescente: novas posições acompanham a queda.'; }
        elseif ($oiChange < -0.5) { $score=0; $detail='OI caindo: desalavancagem; tendência perde convicção.'; }
        else { $score=$pricePeriodChange>0?2:($pricePeriodChange<0?-2:0); $detail='OI praticamente estável; preço pesa mais no sinal.'; }
    }
    $indicators[] = ['name'=>'Open Interest + Preço','value'=>($oiChange===null?'—':fmtNum($oiChange,2).'% OI'),'score'=>$score,'detail'=>$detail,'icon'=>'∞'];

    foreach ($indicators as $it) $totalScore += (int)$it['score'];
}

$maxAbsScore = 82; // soma dos máximos aproximados definidos acima
$scorePct = $indicators ? (int)round(($totalScore / $maxAbsScore) * 100) : 0;
$scorePct = clamp($scorePct, -100, 100);

if ($scorePct >= 35) { $bias='VIÉS DE ALTA'; $biasClass='bull'; $summary='Confluência técnica favorável aos compradores.'; }
elseif ($scorePct <= -35) { $bias='VIÉS DE BAIXA'; $biasClass='bear'; $summary='Confluência técnica favorável aos vendedores.'; }
else { $bias='MERCADO NEUTRO'; $biasClass='neutral'; $summary='Os sinais estão mistos ou sem convicção suficiente.'; }

$bullCount = count(array_filter($indicators, fn($x)=>$x['score'] >= 6));
$bearCount = count(array_filter($indicators, fn($x)=>$x['score'] <= -6));
$neutralCount = count($indicators)-$bullCount-$bearCount;

// Sparkline SVG points
$spark = array_slice($closes,-70);
$sparkPoints='';
if ($spark) {
    $minP=min($spark); $maxP=max($spark); $range=max($maxP-$minP,0.000001); $n=count($spark);
    foreach($spark as $i=>$v){ $x=$n<=1?0:($i/($n-1))*700; $y=150-(($v-$minP)/$range)*120-15; $sparkPoints.=number_format($x,1,'.','').','.number_format($y,1,'.','').' '; }
}

$updatedAt = date('d/m/Y H:i:s');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>Bitcoin Pulse — Painel de Indicadores Binance</title>
<style>
:root{
 --bg:#081019; --bg2:#0c1723; --panel:rgba(17,29,43,.80); --panel2:#111d2b;
 --line:rgba(255,255,255,.08); --text:#eef5ff; --muted:#8fa2b8;
 --bull:#27e6a1; --bear:#ff5f73; --neutral:#f3bd4c; --accent:#f7b928; --blue:#5ca8ff;
 --shadow:0 18px 50px rgba(0,0,0,.28); --radius:22px;
}
*{box-sizing:border-box} body{margin:0;color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:
 radial-gradient(circle at 12% -10%,rgba(247,185,40,.12),transparent 27%),
 radial-gradient(circle at 92% 4%,rgba(92,168,255,.11),transparent 25%),linear-gradient(180deg,#071019 0%,#0b1520 100%);min-height:100vh}
a{color:inherit}.wrap{max-width:1500px;margin:auto;padding:28px 24px 60px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:24px}.brand{display:flex;align-items:center;gap:14px}.coin{width:48px;height:48px;border-radius:15px;display:grid;place-items:center;background:linear-gradient(145deg,#f8cc52,#f4a914);color:#151515;font-weight:900;font-size:26px;box-shadow:0 8px 28px rgba(247,185,40,.22)}.brand h1{margin:0;font-size:21px;letter-spacing:-.02em}.brand small,.muted{color:var(--muted)}.controls{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.chip{padding:9px 12px;border:1px solid var(--line);border-radius:12px;text-decoration:none;color:var(--muted);background:rgba(255,255,255,.025);font-size:13px;font-weight:700}.chip.active{color:#17120a;background:var(--accent);border-color:transparent}.hero{display:grid;grid-template-columns:1.28fr .72fr;gap:18px;margin-bottom:18px}.card{background:linear-gradient(180deg,rgba(19,33,48,.90),rgba(12,24,36,.92));border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}.price-card{padding:26px;position:relative;overflow:hidden}.price-card:after{content:"₿";position:absolute;right:16px;top:-48px;font-size:220px;font-weight:900;color:rgba(247,185,40,.035);transform:rotate(12deg)}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.16em;color:var(--muted);font-weight:800}.price{font-size:52px;line-height:1.05;font-weight:850;letter-spacing:-.045em;margin:10px 0}.delta{font-weight:800}.bull-t{color:var(--bull)}.bear-t{color:var(--bear)}.neutral-t{color:var(--neutral)}.stats{display:flex;gap:22px;flex-wrap:wrap;margin-top:16px}.stat b{display:block;font-size:16px}.stat span{font-size:12px;color:var(--muted)}.spark{margin-top:18px;width:100%;height:145px;display:block}.summary-card{padding:26px;display:flex;flex-direction:column;justify-content:space-between}.gauge-wrap{display:flex;align-items:center;gap:24px}.gauge{--p:50;width:150px;aspect-ratio:1;border-radius:50%;background:conic-gradient(var(--gauge-color) calc(var(--p)*1%),rgba(255,255,255,.07) 0);position:relative;display:grid;place-items:center}.gauge:before{content:"";position:absolute;inset:13px;border-radius:50%;background:#0f1b29}.gauge .inside{position:relative;text-align:center}.gauge strong{font-size:32px;display:block}.bias{font-size:25px;font-weight:900;letter-spacing:-.025em}.summary-card p{color:var(--muted);line-height:1.55}.pills{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:18px}.pill{padding:12px;border-radius:14px;background:rgba(255,255,255,.035);text-align:center}.pill b{font-size:20px;display:block}.pill span{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}.section-head{display:flex;align-items:end;justify-content:space-between;gap:16px;margin:30px 0 14px}.section-head h2{margin:0;font-size:22px}.section-head p{margin:0;color:var(--muted);font-size:13px}.grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px}.indicator{padding:18px;min-height:212px;position:relative;overflow:hidden}.indicator .row{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}.icon{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;background:rgba(255,255,255,.055);font-size:19px}.badge{padding:7px 9px;border-radius:999px;font-size:11px;font-weight:900;letter-spacing:.05em}.badge.bull{background:rgba(39,230,161,.12);color:var(--bull)}.badge.bear{background:rgba(255,95,115,.12);color:var(--bear)}.badge.neutral{background:rgba(243,189,76,.12);color:var(--neutral)}.indicator h3{margin:15px 0 6px;font-size:15px}.indicator .value{font-size:21px;font-weight:850;letter-spacing:-.025em}.indicator p{color:var(--muted);font-size:12.5px;line-height:1.45;margin:10px 0 0}.bar{position:absolute;left:18px;right:18px;bottom:16px;height:4px;background:rgba(255,255,255,.06);border-radius:4px;overflow:hidden}.bar span{display:block;height:100%;border-radius:4px}.notice{margin-top:18px;padding:16px 18px;border-radius:16px;border:1px solid rgba(243,189,76,.18);background:rgba(243,189,76,.055);color:#d7c69e;font-size:12.5px;line-height:1.55}.errors{margin:0 0 18px;padding:14px 16px;border:1px solid rgba(255,95,115,.25);background:rgba(255,95,115,.08);border-radius:14px;color:#ffc7cf}.footer{display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap;color:var(--muted);font-size:12px;margin-top:24px}.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--bull);box-shadow:0 0 16px var(--bull);margin-right:7px}
@media(max-width:1150px){.grid{grid-template-columns:repeat(3,1fr)}.hero{grid-template-columns:1fr}}
@media(max-width:760px){.wrap{padding:18px 14px 40px}.topbar{align-items:flex-start;flex-direction:column}.controls{justify-content:flex-start}.grid{grid-template-columns:1fr}.price{font-size:40px}.gauge-wrap{align-items:flex-start}.gauge{width:125px}.pills{grid-template-columns:repeat(3,1fr)}}
</style>
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <div class="brand">
      <div class="coin">₿</div>
      <div><h1>Bitcoin Pulse</h1><small><span class="dot"></span>Binance BTC/USDT • atualização automática a cada 60 s</small></div>
    </div>
    <nav class="controls" aria-label="Timeframe">
      <?php foreach($allowedIntervals as $iv): ?>
        <a class="chip <?= $iv===$interval?'active':'' ?>" href="?interval=<?= htmlspecialchars($iv) ?>"><?= htmlspecialchars($iv) ?></a>
      <?php endforeach; ?>
    </nav>
  </header>

  <?php if($errors): ?><div class="errors"><?= htmlspecialchars(implode(' ', $errors)) ?></div><?php endif; ?>

  <section class="hero">
    <div class="card price-card">
      <div class="eyebrow">Bitcoin / Tether • <?= htmlspecialchars($interval) ?></div>
      <div class="price"><?= fmtMoney($currentPrice) ?></div>
      <div class="delta <?= (($change24h??0)>=0)?'bull-t':'bear-t' ?>"><?= ($change24h??0)>=0?'▲':'▼' ?> <?= fmtNum(abs($change24h),2) ?>% nas últimas 24h</div>
      <div class="stats">
        <div class="stat"><b><?= fmtMoney($high24h) ?></b><span>Máxima 24h</span></div>
        <div class="stat"><b><?= fmtMoney($low24h) ?></b><span>Mínima 24h</span></div>
        <div class="stat"><b><?= $quoteVolume24h ? '$ '.number_format($quoteVolume24h/1e9,2,',','.').' bi' : '—' ?></b><span>Volume 24h</span></div>
      </div>
      <svg class="spark" viewBox="0 0 700 150" preserveAspectRatio="none" role="img" aria-label="Movimento recente do BTC">
        <defs><linearGradient id="fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#f7b928" stop-opacity=".28"/><stop offset="1" stop-color="#f7b928" stop-opacity="0"/></linearGradient></defs>
        <polyline points="<?= htmlspecialchars(trim($sparkPoints)) ?>" fill="none" stroke="#f7b928" stroke-width="3" vector-effect="non-scaling-stroke"/>
        <?php if($sparkPoints): ?><polygon points="0,150 <?= htmlspecialchars(trim($sparkPoints)) ?> 700,150" fill="url(#fill)"/><?php endif; ?>
      </svg>
    </div>

    <div class="card summary-card">
      <?php $gaugeColor=$biasClass==='bull'?'#27e6a1':($biasClass==='bear'?'#ff5f73':'#f3bd4c'); $gaugeP=50+($scorePct/2); ?>
      <div>
        <div class="eyebrow">Leitura combinada dos 10 indicadores</div>
        <div class="gauge-wrap" style="margin-top:22px">
          <div class="gauge" style="--p:<?= $gaugeP ?>;--gauge-color:<?= $gaugeColor ?>"><div class="inside"><strong><?= $scorePct>0?'+':'' ?><?= $scorePct ?></strong><span class="muted">score</span></div></div>
          <div><div class="bias <?= $biasClass.'-t' ?>"><?= $bias ?></div><p><?= htmlspecialchars($summary) ?></p></div>
        </div>
      </div>
      <div class="pills">
        <div class="pill"><b class="bull-t"><?= $bullCount ?></b><span>Alta</span></div>
        <div class="pill"><b class="neutral-t"><?= $neutralCount ?></b><span>Neutros</span></div>
        <div class="pill"><b class="bear-t"><?= $bearCount ?></b><span>Baixa</span></div>
      </div>
    </div>
  </section>

  <div class="section-head"><div><h2>10 indicadores de antecipação</h2><p>Confluência técnica, fluxo e derivativos — não é uma promessa de direção futura.</p></div><p>Última leitura: <?= $updatedAt ?> BRT</p></div>

  <section class="grid">
    <?php foreach($indicators as $it): [$label,$cls]=signalMeta((int)$it['score']); $barWidth=min(100,abs((int)$it['score'])*10); $barColor=$cls==='bull'?'var(--bull)':($cls==='bear'?'var(--bear)':'var(--neutral)'); ?>
      <article class="card indicator">
        <div class="row"><div class="icon"><?= htmlspecialchars($it['icon']) ?></div><div class="badge <?= $cls ?>"><?= $label ?> <?= $it['score']>0?'+':'' ?><?= (int)$it['score'] ?></div></div>
        <h3><?= htmlspecialchars($it['name']) ?></h3>
        <div class="value"><?= htmlspecialchars($it['value']) ?></div>
        <p><?= htmlspecialchars($it['detail']) ?></p>
        <div class="bar"><span style="width:<?= $barWidth ?>%;background:<?= $barColor ?>"></span></div>
      </article>
    <?php endforeach; ?>
  </section>

  <div class="notice"><strong>Atenção:</strong> o score é uma heurística para organizar sinais de mercado. RSI, médias, book, funding e open interest podem falhar ou mudar rapidamente. Use o painel como apoio à análise, não como ordem automática de compra/venda. Antes de operar capital real, valide a lógica em dados históricos e em simulação.</div>

  <footer class="footer"><span>Fonte: Binance Spot + Binance USDⓈ-M Futures • Sem chave de API</span><span>Bitcoin Pulse • PHP single-file</span></footer>
</div>
</body>
</html>
