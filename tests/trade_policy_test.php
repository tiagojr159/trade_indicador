<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/trade_exec.php';
function verify(bool $value,string $message): void {
    if (!$value) throw new RuntimeException($message);
    echo "OK: $message\n";
}
$prediction=['label'=>2,'frequencies'=>[.1,.1,.8],'count'=>25,'effective'=>20,
    'average'=>.8,'standardError'=>.02];
$p=tradePolicy();
verify(tradeCandidate($prediction)['label']===2,'Strong profitable candidate is allowed for evaluation');
verify(tradeCandidate(array_replace($prediction,['label'=>1]))['label']===1,'Lateral never becomes a forced trade');
verify(tradeCandidate(array_replace($prediction,['average'=>.10]))['label']===1,'Costs block a nominally positive move');
verify(tradeCandidate(array_replace($prediction,['effective'=>3]))['label']===1,'Concentrated neighbor weights block entries');
verify(tradeDecision($prediction,['approved'=>true],false)['label']===1,'Stale quotes cannot open trades');
verify(tradeDecision($prediction,['approved'=>false],true)['label']===1,'Unvalidated signals cannot open trades');
verify(tradeDecision($prediction,['approved'=>true],true)['label']===2,'Fresh validated candidate may enter');
$short=array_replace($prediction,['label'=>0,'frequencies'=>[.8,.1,.1],'average'=>-.8]);
verify(tradeCandidate($short)['label']===0,'Short candidates use the correct sign');
$tests=[];
for($i=0;$i<12;$i++) $tests[]=['end'=>100+$i*300,'candidate'=>2,'candidateNetPct'=>.2];
verify(!tradeEvidence($tests,100)['approved'],'Unresolved outcomes cannot validate a strategy');
verify(tradeEvidence($tests,4000)['approved'],'Sufficient consistently positive earlier outcomes validate');
$future=array_merge($tests,[['end'=>9000,'candidate'=>0,'candidateNetPct'=>-100]]);
verify(tradeEvidence($future,4000)===tradeEvidence($tests,4000),'Future losses cannot leak into current validation');
$losses=array_map(static fn($t)=>array_replace($t,['candidateNetPct'=>-.1]),$tests);
verify(!tradeEvidence($losses,4000)['approved'],'Losing history does not validate');
$state=['side'=>'LONG','entry'=>100,'entry_ts'=>1000,'qty'=>.25,'cash'=>100,'cost_pct'=>.24];
verify(tradeExitReason($state,99,1001)==='Limite de perda','Loss stop triggers independently of forecasts');
verify(tradeExitReason($state,101,1001)==='Realização de lucro','Profit exit triggers');
verify(tradeExitReason($state,100,1300)==='Horizonte concluído','Position expires at its original horizon');
verify(tradeExitReason($state,100.1,1100)===null,'Position is not churned on a new quote');
verify(abs(tsUnrealized($state,100)+.06)<1e-9,'Open PnL provisions round-trip costs');
verify(abs(tsUnrealized(array_replace($state,['side'=>'SHORT']),99)-.19)<1e-9,'Short net PnL includes costs');
$sample=['time'=>1000,'end'=>1300,'btc'=>100,'return'=>1.0];
$outcome=tradeReplayOutcome([['time'=>1060,'btc'=>99],['time'=>1300,'btc'=>101]],$sample,2);
verify($outcome['end']===1060 && abs($outcome['net']+1.24)<1e-9,'Replay fills the first observed stop price, not the future recovery');
$legacy=['label'=>0,'frequencies'=>[.8,.1,.1]];
$adaptive=['label'=>2,'frequencies'=>[.1,.1,.8]];
$trials=[];
for($i=0;$i<12;$i++) $trials[]=['end'=>100+$i*300,'actual'=>2,'legacy'=>$legacy,'adaptive'=>$adaptive];
verify(spChoosePrediction($adaptive,$legacy,$trials,100)['model']==='legacy','Unresolved challenger evaluations cannot promote it');
verify(spChoosePrediction($adaptive,$legacy,$trials,4000)['model']==='adaptive','Earlier improvement promotes challenger');
$trials[]=['end'=>9000,'actual'=>0,'legacy'=>$legacy,'adaptive'=>$adaptive];
verify(spChoosePrediction($adaptive,$legacy,$trials,4000)['model']==='adaptive','Future selection results are excluded');
// Synthetic rows: tests do not depend on a private SQL export or current DB.
$raw=[];
for($i=0;$i<150;$i++) {
    $row=['created_at'=>date('Y-m-d H:i:s',1700000000+$i*60),'btc_price'=>100+sin($i/10),
        'interval_used'=>'1h','indicator_names'=>range(1,50)];
    for($k=1;$k<=50;$k++) $row[sprintf('indicator_%02d',$k)]=($i+$k)%5-2;
    $raw[]=$row;
}
$all=spPrepare($raw);$prefix=spPrepare(array_slice($raw,0,100));
verify($all[99]===$prefix[99],'Past price context is invariant to appended future rows');
$samples=spSamples($all,5,.1);$query=$all[99];
$past=array_values(array_filter($samples,static fn($s)=>$s['end']<$query['time']));
verify(spPredict($samples,$query)===spPredict($past,$query),'Features and predictions use only resolved outcomes');
$weights=spFeatureWeights(array_map(static fn($s)=>array_replace($s,['x'=>array_fill(0,50,1.0)]),$past));
verify(abs(array_sum($weights))<1e-12,'Constant indicators add no similarity evidence');
$a=spAnalyze($all,5,.1);$b=tsBacktest($a);
verify($b['trades']===0 && $b['win_rate']===null,'No trades is not presented as 100 percent success');
echo "All policy and causal validation tests passed.\n";
