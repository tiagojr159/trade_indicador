<?php
declare(strict_types=1);
require_once __DIR__ . '/previsao_dados.php';
require_once __DIR__ . '/trade_signal_state.php';

const LIVE_INITIAL_BALANCE = 100.0;
const LIVE_THRESHOLD = .1;
const LIVE_HORIZON_MINUTES = 5;

function tsPdo(): PDO
{
    $pdo = appPdo();
    $pdo->exec((string)file_get_contents(__DIR__ . '/criar_tabela_trade_simulado.sql'));
    $columns = [
        'is_live' => 'TINYINT(1) NOT NULL DEFAULT 0', 'event_type' => 'VARCHAR(24) NULL',
        'position_side' => 'VARCHAR(8) NULL', 'position_qty' => 'DECIMAL(24,12) NOT NULL DEFAULT 0',
        'position_entry_price' => 'DECIMAL(20,8) NULL', 'cash_balance' => 'DECIMAL(20,8) NOT NULL DEFAULT 100',
        'equity_after' => 'DECIMAL(20,8) NOT NULL DEFAULT 100', 'unrealized_pnl_usd' => 'DECIMAL(20,8) NOT NULL DEFAULT 0',
        'model_version' => 'VARCHAR(32) NULL', 'cost_pct' => 'DECIMAL(8,4) NOT NULL DEFAULT 0',
        'fees_usd' => 'DECIMAL(20,8) NOT NULL DEFAULT 0',
    ];
    $existing = array_column($pdo->query('SHOW COLUMNS FROM trade_simulado')->fetchAll(), 'Field');
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing, true)) {
            try { $pdo->exec("ALTER TABLE trade_simulado ADD COLUMN `$name` $definition"); }
            catch (PDOException $error) { if (($error->errorInfo[1] ?? 0) !== 1060) throw $error; }
        }
    }
    return $pdo;
}

function tsLiveRunId(PDO $pdo): string
{
    $row = $pdo->query('SELECT run_id FROM trade_simulado WHERE is_live=1 ORDER BY id DESC LIMIT 1')->fetch();
    return $row ? $row['run_id'] : 'live_' . date('Ymd');
}

function tsLastState(PDO $pdo, string $runId): array
{
    $stmt = $pdo->prepare('SELECT * FROM trade_simulado WHERE is_live=1 AND run_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$runId]);
    $last = $stmt->fetch();
    return ['side' => $last['position_side'] ?? 'FLAT',
        'entry' => isset($last['position_entry_price']) ? (float)$last['position_entry_price'] : null,
        'qty' => (float)($last['position_qty'] ?? 0), 'cash' => (float)($last['cash_balance'] ?? LIVE_INITIAL_BALANCE),
        'entry_ts' => $last ? strtotime($last['entry_time']) : 0,
        'cost_pct' => (float)($last['cost_pct'] ?? 0), 'origin' => $last ?: null];
}

function tsUnrealized(array $state, float $price): float
{
    if ($state['side'] === 'FLAT' || !$state['entry']) return 0;
    return ($price-$state['entry'])*$state['qty']*($state['side']==='LONG'?1:-1)
        - $state['entry']*$state['qty']*$state['cost_pct']/100;
}

function tsInsert(PDO $pdo, array $row): void
{
    $columns = array_keys($row);
    $stmt = $pdo->prepare('INSERT INTO trade_simulado (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
    $stmt->execute(array_values($row));
}

function tsEvent(string $runId, array $data, array $state, int $time): array
{
    $p = $data['prediction'];
    $price = (float)$data['latest']['btc'];
    return ['run_id'=>$runId,'is_live'=>1,'model_version'=>TRADE_MODEL_VERSION,
        'strategy_minutes'=>LIVE_HORIZON_MINUTES,'threshold_pct'=>LIVE_THRESHOLD,'initial_balance'=>LIVE_INITIAL_BALANCE,
        'balance_before'=>$state['cash'],'balance_after'=>$state['cash'],'entry_time'=>date('Y-m-d H:i:s',$time),
        'exit_time'=>date('Y-m-d H:i:s',$time),'entry_price'=>$price,'exit_price'=>$price,
        'btc_return_pct'=>0,'trade_return_pct'=>0,'pnl_usd'=>0,'predicted_label'=>$p['label']??1,
        'actual_label'=>1,'was_correct'=>0,'neighbors_count'=>$p['count']??0,'similarity'=>$p['similarity']??0,
        'probability_up'=>$p['frequencies'][2]??0,'probability_flat'=>$p['frequencies'][1]??0,'probability_down'=>$p['frequencies'][0]??0,
        'position_qty'=>0,'position_entry_price'=>null,'cash_balance'=>$state['cash'],'equity_after'=>$state['cash'],
        'unrealized_pnl_usd'=>0,'cost_pct'=>0,'fees_usd'=>0];
}

function tsExecute(PDO $pdo, int $intervalSeconds = 60, ?array $data = null): array
{
    // Cron and browser share the same lock and transaction. A quote cannot fill twice.
    $lock = fopen(__DIR__.'/data/trade_execution.lock','c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        return ['ok'=>true,'executed'=>false,'message'=>'Execução em andamento.'];
    }
    try {
        $data = $data ?? spData(LIVE_HORIZON_MINUTES,LIVE_THRESHOLD);
        $runId = tsLiveRunId($pdo);
        $decision = $data['decision'];
        tradeSignalSave($data['prediction'], $data['latest'], ['source'=>'trade_engine','run_id'=>$runId,
            'minutes'=>LIVE_HORIZON_MINUTES,'threshold'=>LIVE_THRESHOLD,'trade_label'=>$decision['label'],
            'model_version'=>TRADE_MODEL_VERSION,'notes'=>$decision['reason']]);
        if (!$data['fresh'] || !$data['latest']) return ['ok'=>true,'executed'=>false,'message'=>$decision['reason']];
        $price = (float)$data['latest']['btc'];
        $time = (int)$data['latest']['time'];
        $policy = tradePolicy();
        $pdo->beginTransaction();
        $state = tsLastState($pdo,$runId);
        $origin = $state['origin'];
        // Never execute a quote already consumed by an earlier order, even after a page reload.
        if ($origin && $time <= strtotime($origin['exit_time'])) {
            $pdo->commit();
            return ['ok'=>true,'executed'=>false,'message'=>'Aguardando nova coleta.'];
        }
        if ($state['side'] !== 'FLAT') {
            $reason = tradeExitReason($state,$price,$time,$policy);
            if ($reason === null) {
                $pdo->commit();
                return ['ok'=>true,'executed'=>false,'message'=>'Posição monitorada até o limite de risco ou horizonte.'];
            }
            $grossReturn = ($price/$state['entry']-1)*100;
            $netReturn = $grossReturn*($state['side']==='LONG'?1:-1)-$state['cost_pct'];
            $pnl = tsUnrealized($state,$price);
            $cash = max(0,$state['cash']+$pnl);
            $event = tsEvent($runId,$data,$state,$time);
            // Keep the prediction, timestamp and frequencies that actually opened the position.
            foreach (['entry_time','predicted_label','neighbors_count','similarity','probability_up','probability_flat','probability_down','model_version'] as $field) {
                $event[$field] = $origin[$field];
            }
            $event = array_replace($event, ['event_type'=>'CLOSE_'.$state['side'],'side'=>$state['side'],
                'entry_price'=>$state['entry'],'btc_return_pct'=>$grossReturn,'trade_return_pct'=>$netReturn,
                'pnl_usd'=>$pnl,'balance_after'=>$cash,'cash_balance'=>$cash,'equity_after'=>$cash,
                'actual_label'=>spLabel($grossReturn,LIVE_THRESHOLD),
                'was_correct'=>(int)((int)$origin['predicted_label']===spLabel($grossReturn,LIVE_THRESHOLD)),
                'position_side'=>'FLAT','notes'=>$reason.($state['cost_pct']>0?' · custos incluídos.':' · posição legada sem custos.'),
                'cost_pct'=>$state['cost_pct'],'fees_usd'=>$state['entry']*$state['qty']*$state['cost_pct']/100]);
            tsInsert($pdo,$event);
            $pdo->commit();
            return ['ok'=>true,'executed'=>true,'message'=>$reason.'. Resultado líquido registrado.'];
        }
        $intervalSeconds = in_array($intervalSeconds,[60,300],true)?$intervalSeconds:60;
        if ($origin && $time-strtotime($origin['exit_time']) < $intervalSeconds) {
            $pdo->commit();
            return ['ok'=>true,'executed'=>false,'message'=>'Aguardando intervalo após fechamento.'];
        }
        if ($decision['label']===1 || $state['cash']<=0) {
            $pdo->commit();
            return ['ok'=>true,'executed'=>false,'message'=>$decision['reason']];
        }
        $side = $decision['label']===2?'LONG':'SHORT';
        $qty = $state['cash']*$policy['allocation']/$price;
        $cost = tradeRoundTripCost($policy);
        $estimatedCosts = $qty*$price*$cost/100;
        tsInsert($pdo,array_replace(tsEvent($runId,$data,$state,$time),[
            'event_type'=>'OPEN_'.$side,'side'=>$side,'position_side'=>$side,'position_qty'=>$qty,
            'position_entry_price'=>$price,'cost_pct'=>$cost,'equity_after'=>$state['cash']-$estimatedCosts,
            'unrealized_pnl_usd'=>-$estimatedCosts,'notes'=>'Sinal validado; 25% do saldo; custos provisionados e liquidados no fechamento.']));
        $pdo->commit();
        return ['ok'=>true,'executed'=>true,'message'=>'Posição simulada aberta com controle de risco.'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

function tsSnapshot(PDO $pdo, string $runId, string $message = ''): array
{
    $data=spData(LIVE_HORIZON_MINUTES,LIVE_THRESHOLD);
    $state=tsLastState($pdo,$runId);
    $price=(float)($data['latest']['btc']??0);
    $unrealized=$price>0?tsUnrealized($state,$price):0;
    $stmt=$pdo->prepare('SELECT * FROM trade_simulado WHERE is_live=1 AND run_id=? ORDER BY id DESC LIMIT 80');
    $stmt->execute([$runId]);
    return ['run_id'=>$runId,'message'=>$message?:$data['decision']['reason'],'price'=>$price,'state'=>$state,
        'unrealized'=>$unrealized,'equity'=>$state['cash']+$unrealized,'prediction'=>$data['prediction'],
        'decision'=>$data['decision'],'evidence'=>$data['evidence'],'fresh'=>$data['fresh'],'policy'=>$data['policy'],
        'metrics'=>$data['metrics'],'latest'=>$data['latest'],'orders'=>$stmt->fetchAll()];
}

function tsBacktest(array $data): array
{
    $balance=LIVE_INITIAL_BALANCE;$orders=[];$wins=$losses=$skipped=0;$peak=$balance;$drawdown=0;
    $policy=tradePolicy();$grossWins=$grossLosses=0.0;
    foreach ($data['tests']??[] as $test) {
        $label=$test['tradeLabel']??1;
        if ($label===1) { $skipped++;continue; }
        $before=$balance;
        $net=(float)$test['candidateNetPct'];
        $pnl=$before*$policy['allocation']*$net/100;
        $balance=max(0,$balance+$pnl);$peak=max($peak,$balance);
        $drawdown=max($drawdown,($peak-$balance)/$peak*100);
        $wins+=(int)($pnl>0);$losses+=(int)($pnl<0);$grossWins+=max(0,$pnl);$grossLosses+=max(0,-$pnl);
        $orders[]=['time'=>$test['time'],'end'=>$test['tradeEnd'],'side'=>$label===2?'Compra':'Venda',
            'btc_return'=>$test['tradeBtcReturn'],'trade_return'=>$net,'pnl'=>$pnl,'balance_before'=>$before,
            'balance_after'=>$balance,'correct'=>$label===$test['actual']];
    }
    $total=count($orders);
    return ['initial'=>LIVE_INITIAL_BALANCE,'balance'=>$balance,'pnl'=>$balance-LIVE_INITIAL_BALANCE,
        'return_pct'=>($balance/LIVE_INITIAL_BALANCE-1)*100,'orders'=>array_reverse($orders),
        'trades'=>$total,'skipped'=>$skipped,'wins'=>$wins,'losses'=>$losses,'win_rate'=>$total?$wins/$total:null,
        'source_tests'=>count($data['tests']??[]),'drawdown'=>$drawdown,'profit_factor'=>$grossLosses>0?$grossWins/$grossLosses:null];
}
