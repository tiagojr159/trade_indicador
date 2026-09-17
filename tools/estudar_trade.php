<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/trade_exec.php';

// Read only: does not collect quotes, execute orders, migrate or change the DB.
$path = $argv[1] ?? null;
if ($path) {
    $payload = json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    $raw = $payload['rows'];
} else {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET,DB_USER,DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $raw = array_reverse($pdo->query('SELECT * FROM indicador_historico ORDER BY created_at DESC,id DESC LIMIT 6000')->fetchAll());
}
$rows = spPrepare($raw);
if (!$rows) throw new RuntimeException('Sem registros válidos para análise.');
$report = ['generated'=>date('c'),'version'=>TRADE_MODEL_VERSION,'records'=>count($rows),
    'first'=>$rows[0]['date'],'last'=>$rows[count($rows)-1]['date'],
    'policy'=>tradePolicy(),'snapshot_sha256'=>hash('sha256',json_encode($raw)),
    'method'=>'Walk-forward: apenas resultados encerrados antes de cada previsão; avaliação sem sobreposição. Parâmetros fixos, sem busca no período de teste. Último terço informado separadamente; não é uma amostra externa independente.',
    'results'=>[]];
foreach ([5,15,30,60] as $minutes) {
    $models = ['legacy'=>spAnalyze($rows,$minutes,.1,spLegacyPredictionConfig($minutes,.1)),
        'adaptive'=>spAnalyze($rows,$minutes,.1)];
    foreach ($models as $name=>$analysis) {
        $balance=100;$trades=$wins=0;
        foreach ($analysis['tests'] as $t) {
            if ($t['predicted']===1) continue;
            $net=$t['return']*($t['predicted']===2?1:-1)-tradeRoundTripCost();
            $balance*=1+$net/100; $trades++; $wins+=(int)($net>0);
        }
        $lastThird=array_slice($analysis['tests'],(int)floor(count($analysis['tests'])*2/3));
        $correct=$directional=$directionCorrect=0;
        foreach ($lastThird as $t) {
            $correct+=(int)($t['predicted']===$t['actual']);
            if ($t['predicted']!==1) { $directional++;$directionCorrect+=(int)($t['predicted']===$t['actual']); }
        }
        $report['results'][$minutes][$name]=['metrics'=>$analysis['metrics'],
            'raw_directional_trades'=>$trades,'raw_directional_net_wins'=>$wins,
            'raw_directional_balance_100pct_allocation_after_costs'=>$balance,
            'last_third'=>['count'=>count($lastThird),'accuracy'=>$lastThird?$correct/count($lastThird):null,
                'directional'=>$directional,'directionAccuracy'=>$directional?$directionCorrect/$directional:null]];
        if ($name==='adaptive') {
            $backtest=tsBacktest($analysis);
            unset($backtest['orders']);
            $report['results'][$minutes][$name]['filtered_simulation']=$backtest;
            $report['results'][$minutes][$name]['evidence']=$analysis['evidence'];
        }
    }
}
$output=dirname(__DIR__).'/data/trade_estudo_resultado.json';
file_put_contents($output,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
