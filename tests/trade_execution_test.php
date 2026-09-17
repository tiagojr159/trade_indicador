<?php
declare(strict_types=1);
define('TRADE_SIGNAL_STATE_FILE',sys_get_temp_dir().'/trade_test_'.getmypid().'.json');
require_once dirname(__DIR__).'/trade_exec.php';
function assertTrade(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "OK: $message\n";
}
// Isolated in-memory ledger: never touches the user's MySQL orders.
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE trade_simulado (
 id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 run_id TEXT,is_live INTEGER,event_type TEXT,model_version TEXT,strategy_minutes INTEGER,threshold_pct REAL,
 initial_balance REAL,balance_before REAL,balance_after REAL,entry_time TEXT,exit_time TEXT,side TEXT,
 entry_price REAL,exit_price REAL,btc_return_pct REAL,trade_return_pct REAL,pnl_usd REAL,predicted_label INTEGER,
 actual_label INTEGER,was_correct INTEGER,neighbors_count INTEGER,similarity REAL,probability_up REAL,
 probability_flat REAL,probability_down REAL,position_side TEXT,position_qty REAL,position_entry_price REAL,
 cash_balance REAL,equity_after REAL,unrealized_pnl_usd REAL,cost_pct REAL,fees_usd REAL,notes TEXT)');
$prediction=['label'=>2,'frequencies'=>[.1,.1,.8],'count'=>25,'effective'=>20,'average'=>.8,'standardError'=>.02,'similarity'=>.9];
$data=['prediction'=>$prediction,'latest'=>['btc'=>100.0,'time'=>1700000000],
    'fresh'=>true,'decision'=>['label'=>2,'reason'=>'Validated test signal']];
try {
    $stale=array_replace($data,['fresh'=>false]);
    assertTrade(!tsExecute($pdo,60,$stale)['executed'],'Stale quote creates no ledger entry');
    assertTrade(tsExecute($pdo,60,$data)['executed'],'Validated fresh signal opens a simulated position');
    assertTrade(!tsExecute($pdo,60,$data)['executed'],'Repeated quote cannot duplicate an order');
    $run=tsLiveRunId($pdo);$state=tsLastState($pdo,$run);
    assertTrade(abs($state['qty']-.25)<1e-10,'Position uses 25 percent of equity without leverage');
    $next=$data;$next['latest']=['btc'=>100.1,'time'=>1700000060];
    $next['prediction']=null;$next['decision']=['label'=>1,'reason'=>'No forecast'];
    assertTrade(!tsExecute($pdo,60,$next)['executed'],'Missing forecast does not churn a healthy position');
    $next['latest']=['btc'=>100.5,'time'=>1700000300];
    assertTrade(tsExecute($pdo,60,$next)['executed'],'Horizon exit still executes without a forecast');
    $closed=$pdo->query('SELECT * FROM trade_simulado ORDER BY id DESC LIMIT 1')->fetch();
    assertTrade($closed['event_type']==='CLOSE_LONG' && (int)$closed['predicted_label']===2,'Close preserves entry direction');
    assertTrade((int)$closed['actual_label']===2 && (int)$closed['was_correct']===1,'Actual label is calculated from the realized BTC move');
    assertTrade(strtotime($closed['entry_time'])===1700000000 && strtotime($closed['exit_time'])===1700000300,'Original entry and real exit times are preserved');
    assertTrade(abs($closed['fees_usd']-.06)<1e-9 && abs($closed['pnl_usd']-.065)<1e-9,'Round-trip costs are settled exactly once');
    assertTrade(!tsExecute($pdo,60,$next)['executed'],'A close cannot reopen on its consumed quote');
    $data['latest']['time']=1700000360;
    $pdo->exec("CREATE TRIGGER fail_order BEFORE INSERT ON trade_simulado BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    try { tsExecute($pdo,60,$data); throw new RuntimeException('Failure was not propagated'); }
    catch (PDOException $expected) { assertTrade(!$pdo->inTransaction(),'Failed order rolls back the transaction'); }
    assertTrade((int)$pdo->query('SELECT COUNT(*) FROM trade_simulado')->fetchColumn()===2,'Failure leaves the original ledger intact');
    $pdo->exec('DROP TRIGGER fail_order');
    assertTrade(tsExecute($pdo,60,$data)['executed'],'Execution lock is released after failure');
    $state=tsLastState($pdo,$run);
    $beforeCount=(int)$pdo->query('SELECT COUNT(*) FROM trade_simulado')->fetchColumn();
    $data['latest']=['btc'=>99.0,'time'=>1700000420];
    $data['fresh']=false;
    assertTrade(!tsExecute($pdo,60,$data)['executed'],'Stale prices cannot fabricate a stop fill');
    $data['fresh']=true;$data['prediction']=null;$data['decision']['label']=1;
    assertTrade(tsExecute($pdo,60,$data)['executed'],'Risk exit remains available without a directional signal');
    assertTrade((int)$pdo->query('SELECT COUNT(*) FROM trade_simulado')->fetchColumn()===$beforeCount+1,'Risk exit writes one event');
    echo "All isolated execution tests passed.\n";
} finally { if (is_file(TRADE_SIGNAL_STATE_FILE)) unlink(TRADE_SIGNAL_STATE_FILE); }
