<?php
declare(strict_types=1);
require_once __DIR__ . '/previsao_dados.php';
require_once __DIR__ . '/trade_signal_state.php';

const LIVE_INITIAL_BALANCE = 100.0;
const LIVE_THRESHOLD = .1;
const LIVE_HORIZON_MINUTES = 5;
// Kept for old ledgers and callers. New simulations use the seven accounts below.
const LIVE_LANES = ['LONG_ONLY' => 'LONG', 'SHORT_ONLY' => 'SHORT'];
const LIVE_STRATEGIES = [
    'adaptive' => ['name' => 'Super Previsão', 'description' => 'Modelo adaptativo com validação histórica após custos.'],
    'median' => ['name' => 'Retorno à mediana', 'description' => 'Compra quando BTC normalizado fica abaixo da média branca dos dez indicadores do gráfico; vende quando fica acima.'],
    'ema_trend' => ['name' => 'Tendência EMA', 'description' => 'Combina os dez sinais de tendência: cruzamentos, médias, inclinação e estrutura.'],
    'momentum' => ['name' => 'Momentum', 'description' => 'Combina RSI, MACD, Estocástico, ROC, CCI, Williams %R e PPO.'],
    'volume_flow' => ['name' => 'Volume e fluxo', 'description' => 'Usa volume relativo, OBV, MFI, VWAP, fluxo e confirmação de preço com volume.'],
    'graph_confirm' => ['name' => 'Confirmação do gráfico', 'description' => 'Combina média dos indicadores selecionados, inclinação recente e variação recente do BTC.'],
    'block_consensus' => ['name' => 'Consenso dos cinco blocos', 'description' => 'Opera apenas quando ao menos quatro dos cinco grupos de indicadores concordam.'],
];

function tsStrategies(): array
{
    return LIVE_STRATEGIES;
}

function tsPdo(): PDO
{
    $pdo = appPdo();
    $pdo->exec((string)file_get_contents(__DIR__ . '/criar_tabela_trade_simulado.sql'));
    $pdo->exec((string)file_get_contents(__DIR__ . '/criar_tabela_trade_contas.sql'));
    $columns = [
        'is_live' => 'TINYINT(1) NOT NULL DEFAULT 0', 'event_type' => 'VARCHAR(24) NULL',
        'position_side' => 'VARCHAR(8) NULL', 'position_qty' => 'DECIMAL(24,12) NOT NULL DEFAULT 0',
        'position_entry_price' => 'DECIMAL(20,8) NULL', 'cash_balance' => 'DECIMAL(20,8) NOT NULL DEFAULT 100',
        'equity_after' => 'DECIMAL(20,8) NOT NULL DEFAULT 100', 'unrealized_pnl_usd' => 'DECIMAL(20,8) NOT NULL DEFAULT 0',
        'model_version' => 'VARCHAR(32) NULL', 'cost_pct' => 'DECIMAL(8,4) NOT NULL DEFAULT 0',
        'fees_usd' => 'DECIMAL(20,8) NOT NULL DEFAULT 0',
        'strategy_lane' => "VARCHAR(32) NOT NULL DEFAULT 'LEGACY'",
    ];
    $columnInfo = $pdo->query('SHOW COLUMNS FROM trade_simulado')->fetchAll();
    $existing = array_column($columnInfo, 'Field');
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing, true)) {
            try { $pdo->exec("ALTER TABLE trade_simulado ADD COLUMN `$name` $definition"); }
            catch (PDOException $error) { if (($error->errorInfo[1] ?? 0) !== 1060) throw $error; }
        }
    }
    foreach ($columnInfo as $column) {
        if (($column['Field'] ?? '') === 'strategy_lane' && stripos((string)$column['Type'], 'varchar(32)') === false) {
            $pdo->exec("ALTER TABLE trade_simulado MODIFY COLUMN strategy_lane VARCHAR(32) NOT NULL DEFAULT 'LEGACY'");
            break;
        }
    }
    $seed = $pdo->prepare('INSERT IGNORE INTO trade_sim_accounts (account_key, account_name, initial_balance) VALUES (?, ?, ?)');
    foreach (tsStrategies() as $key => $strategy) {
        $seed->execute([$key, $strategy['name'], LIVE_INITIAL_BALANCE]);
    }
    return $pdo;
}

function tsLiveRunId(PDO $pdo): string
{
    $row = $pdo->query('SELECT run_id FROM trade_simulado WHERE is_live=1 ORDER BY id DESC LIMIT 1')->fetch();
    return $row ? $row['run_id'] : 'live_' . date('Ymd');
}

function tsLastState(PDO $pdo, string $runId, string $lane = 'LEGACY'): array
{
    $stmt = $pdo->prepare('SELECT * FROM trade_simulado WHERE is_live=1 AND run_id=? AND strategy_lane=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$runId, $lane]);
    $last = $stmt->fetch();
    return ['side' => $last['position_side'] ?? 'FLAT',
        'entry' => isset($last['position_entry_price']) ? (float)$last['position_entry_price'] : null,
        'qty' => (float)($last['position_qty'] ?? 0), 'cash' => (float)($last['cash_balance'] ?? LIVE_INITIAL_BALANCE),
        'entry_ts' => $last ? strtotime($last['entry_time']) : 0,
        'cost_pct' => (float)($last['cost_pct'] ?? 0), 'lane' => $lane, 'origin' => $last ?: null];
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

function tsEvent(string $runId, array $data, array $state, int $time, ?int $predictedLabel = null): array
{
    $p = $data['prediction'] ?? [];
    $price = (float)$data['latest']['btc'];
    return ['run_id'=>$runId,'is_live'=>1,'model_version'=>TRADE_MODEL_VERSION,'strategy_lane'=>$state['lane'] ?? 'LEGACY',
        'strategy_minutes'=>LIVE_HORIZON_MINUTES,'threshold_pct'=>LIVE_THRESHOLD,'initial_balance'=>LIVE_INITIAL_BALANCE,
        'balance_before'=>$state['cash'],'balance_after'=>$state['cash'],'entry_time'=>date('Y-m-d H:i:s',$time),
        'exit_time'=>date('Y-m-d H:i:s',$time),'entry_price'=>$price,'exit_price'=>$price,
        'btc_return_pct'=>0,'trade_return_pct'=>0,'pnl_usd'=>0,'predicted_label'=>$predictedLabel ?? ($p['label']??1),
        'actual_label'=>1,'was_correct'=>0,'neighbors_count'=>$p['count']??0,'similarity'=>$p['similarity']??0,
        'probability_up'=>$p['frequencies'][2]??0,'probability_flat'=>$p['frequencies'][1]??0,'probability_down'=>$p['frequencies'][0]??0,
        'position_qty'=>0,'position_entry_price'=>null,'cash_balance'=>$state['cash'],'equity_after'=>$state['cash'],
        'unrealized_pnl_usd'=>0,'cost_pct'=>0,'fees_usd'=>0];
}

// Legacy hedge executor retained so existing test callers and historical ledgers remain readable.
function tsExecuteLane(PDO $pdo, string $runId, string $lane, string $forcedSide, array $data, int $time, float $price, int $intervalSeconds, array $policy): array
{
    $state = tsLastState($pdo,$runId,$lane);
    $origin = $state['origin'];
    if ($origin && $time <= strtotime($origin['exit_time'])) {
        return ['executed'=>false,'message'=>$lane.': aguardando nova coleta.'];
    }
    if ($state['side'] !== 'FLAT') {
        $reason = tradeExitReason($state,$price,$time,$policy);
        if ($reason === null) return ['executed'=>false,'message'=>$lane.': posiÃ§Ã£o monitorada.'];
        $grossReturn = ($price/$state['entry']-1)*100;
        $netReturn = $grossReturn*($state['side']==='LONG'?1:-1)-$state['cost_pct'];
        $pnl = tsUnrealized($state,$price);
        $cash = max(0,$state['cash']+$pnl);
        $event = tsEvent($runId,$data,$state,$time);
        foreach (['entry_time','predicted_label','neighbors_count','similarity','probability_up','probability_flat','probability_down','model_version'] as $field) $event[$field] = $origin[$field];
        $event = array_replace($event, ['event_type'=>'CLOSE_'.$state['side'],'side'=>$state['side'],
            'entry_price'=>$state['entry'],'btc_return_pct'=>$grossReturn,'trade_return_pct'=>$netReturn,
            'pnl_usd'=>$pnl,'balance_after'=>$cash,'cash_balance'=>$cash,'equity_after'=>$cash,
            'actual_label'=>spLabel($grossReturn,LIVE_THRESHOLD),
            'was_correct'=>(int)((int)$origin['predicted_label']===spLabel($grossReturn,LIVE_THRESHOLD)),
            'position_side'=>'FLAT','notes'=>$reason.' - '.$lane.' - custos liquidados.',
            'cost_pct'=>$state['cost_pct'],'fees_usd'=>$state['entry']*$state['qty']*$state['cost_pct']/100]);
        tsInsert($pdo,$event);
        return ['executed'=>true,'message'=>$lane.': '.$reason.'.'];
    }
    if ($origin && $time-strtotime($origin['exit_time']) < $intervalSeconds) return ['executed'=>false,'message'=>$lane.': aguardando intervalo apÃ³s fechamento.'];
    if ($state['cash']<=0) return ['executed'=>false,'message'=>$lane.': saldo zerado.'];
    $qty = $state['cash']*$policy['allocation']/$price;
    $cost = tradeRoundTripCost($policy);
    $estimatedCosts = $qty*$price*$cost/100;
    tsInsert($pdo,array_replace(tsEvent($runId,$data,$state,$time),[
        'event_type'=>'OPEN_'.$forcedSide,'side'=>$forcedSide,'position_side'=>$forcedSide,'position_qty'=>$qty,
        'position_entry_price'=>$price,'cost_pct'=>$cost,'equity_after'=>$state['cash']-$estimatedCosts,
        'unrealized_pnl_usd'=>-$estimatedCosts,'notes'=>$lane.' sempre '.($forcedSide==='LONG'?'comprado':'vendido').'; 25% do saldo; custos provisionados.']));
    return ['executed'=>true,'message'=>$lane.': abriu '.($forcedSide==='LONG'?'compra':'venda').'.'];
}

function tsMeanFeatures(array $row, array $indexes): ?float
{
    $values = [];
    foreach ($indexes as $index) {
        if (isset($row['x'][$index]) && is_numeric($row['x'][$index])) $values[] = (float)$row['x'][$index];
    }
    return $values ? array_sum($values)/count($values) : null;
}

function tsIndicatorMedian(array $row): ?float
{
    // These are the ten selected indicator lines enabled by default on GrÃ¡ficos Selecionados.
    return tsMeanFeatures($row, [13,14,17,20,25,29,31,32,36,41]);
}

function tsClamp(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function tsStrategySignal(string $strategyKey, array $history, int $index, array $data = [], array $adaptiveSignals = []): array
{
    $flat = ['label'=>1,'reason'=>'Indicadores sem direÃ§Ã£o suficiente.'];
    if (!isset(LIVE_STRATEGIES[$strategyKey]) || !isset($history[$index])) return $flat;
    $row = $history[$index];
    $x = $row['x'] ?? [];
    $direction = static function (?float $score, float $threshold, string $reason): array {
        if ($score !== null && $score >= $threshold) return ['label'=>2,'reason'=>$reason.' favorece alta.'];
        if ($score !== null && $score <= -$threshold) return ['label'=>0,'reason'=>$reason.' favorece baixa.'];
        return ['label'=>1,'reason'=>$reason.' sem confirmaÃ§Ã£o suficiente.'];
    };

    if ($strategyKey === 'adaptive') {
        if ($index === count($history)-1) {
            $label = (int)($data['decision']['label'] ?? 1);
            return ['label'=>$label,'reason'=>(string)($data['decision']['reason'] ?? 'Aguardando validaÃ§Ã£o do modelo adaptativo.')];
        }
        $label = (int)($adaptiveSignals[(string)$row['time']] ?? 1);
        return ['label'=>$label,'reason'=>$label===1?'Modelo adaptativo sem sinal aprovado.':'Modelo adaptativo aprovado com evidÃªncia anterior.'];
    }

    if ($strategyKey === 'median') {
        $median = tsIndicatorMedian($row);
        $price = (float)($row['btc'] ?? 0);
        if ($median === null || $price <= 0) return ['label'=>1,'reason'=>'Aguardando linhas vÃ¡lidas do grÃ¡fico.'];
        $minimum = $maximum = $price;
        for ($i=$index; $i>=0; $i--) {
            if ((int)$history[$i]['time'] < (int)$row['time']-86400) break;
            $past = (float)$history[$i]['btc'];
            $minimum = min($minimum,$past); $maximum = max($maximum,$past);
        }
        if ($maximum-$minimum <= 0) return ['label'=>1,'reason'=>'HistÃ³rico de preÃ§o curto para normalizar o grÃ¡fico.'];
        // The selected chart maps BTC to [-2, 2] over its trailing 24-hour range.
        $btcLine = (($price-$minimum)/($maximum-$minimum))*4-2;
        $gap = $median-$btcLine;
        if ($gap >= .30) return ['label'=>2,'reason'=>'BTC abaixo da mÃ©dia branca dos indicadores.'];
        if ($gap <= -.30) return ['label'=>0,'reason'=>'BTC acima da mÃ©dia branca dos indicadores.'];
        return ['label'=>1,'reason'=>'BTC prÃ³ximo da mÃ©dia branca; sem afastamento suficiente.'];
    }

    if ($strategyKey === 'ema_trend') return $direction(tsMeanFeatures($row,range(0,9)),.55,'TendÃªncia das EMAs');
    if ($strategyKey === 'momentum') return $direction(tsMeanFeatures($row,range(10,19)),.35,'Momentum dos osciladores');
    if ($strategyKey === 'volume_flow') return $direction(tsMeanFeatures($row,range(20,29)),.35,'Volume e fluxo');

    if ($strategyKey === 'graph_confirm') {
        $first = max(0,$index-11); $medians=[];
        for ($i=$first;$i<=$index;$i++) {
            $value=tsIndicatorMedian($history[$i]);
            if ($value!==null) $medians[]=$value;
        }
        if (count($medians)<3) return ['label'=>1,'reason'=>'Poucos pontos para confirmar a tendÃªncia do grÃ¡fico.'];
        $average=array_sum($medians)/count($medians);
        $slope=$medians[count($medians)-1]-$medians[max(0,count($medians)-8)];
        $pastIndex=max(0,$index-5);$pastPrice=(float)$history[$pastIndex]['btc'];
        $btcMove=$pastPrice>0?($row['btc']/$pastPrice-1)*100:0;
        $score=tsClamp($average/2,-1,1)*.55+tsClamp($slope/2,-1,1)*.30+tsClamp($btcMove/1.5,-1,1)*.15;
        if ($score>=.2222) return ['label'=>2,'reason'=>'Probabilidade heurÃ­stica do grÃ¡fico acima de 60%.'];
        if ($score<=-.2222) return ['label'=>0,'reason'=>'Probabilidade heurÃ­stica do grÃ¡fico abaixo de 40%.'];
        return ['label'=>1,'reason'=>'Probabilidade heurÃ­stica do grÃ¡fico entre 40% e 60%.'];
    }

    if ($strategyKey === 'block_consensus') {
        $scores=[];
        for ($group=0;$group<5;$group++) $scores[]=tsMeanFeatures($row,range($group*10,$group*10+9));
        $valid=array_values(array_filter($scores,static fn($value):bool=>$value!==null));
        if (count($valid)<4) return ['label'=>1,'reason'=>'Faltam blocos de indicadores para formar consenso.'];
        $up=count(array_filter($valid,static fn(float $value):bool=>$value>=.2));
        $down=count(array_filter($valid,static fn(float $value):bool=>$value<=-.2));
        $average=array_sum($valid)/count($valid);
        if ($up>=4 && $average>=.25) return ['label'=>2,'reason'=>'Ao menos quatro dos cinco blocos apontam alta.'];
        if ($down>=4 && $average<=-.25) return ['label'=>0,'reason'=>'Ao menos quatro dos cinco blocos apontam baixa.'];
        return ['label'=>1,'reason'=>'Os cinco blocos ainda divergem.'];
    }
    return $flat;
}

function tsExecuteStrategy(PDO $pdo, string $runId, string $strategyKey, array $signal, array $data, int $time, float $price, int $intervalSeconds, array $policy): array
{
    $state=tsLastState($pdo,$runId,$strategyKey);$origin=$state['origin'];$label=(int)$signal['label'];
    if ($origin && $time<=strtotime($origin['exit_time'])) return ['executed'=>false,'message'=>$strategyKey.': aguardando nova coleta.'];
    if ($state['side']!=='FLAT') {
        $reason=tradeExitReason($state,$price,$time,$policy);
        if ($reason===null && (($state['side']==='LONG' && $label===0) || ($state['side']==='SHORT' && $label===2))) $reason='Sinal contrÃ¡rio';
        if ($reason===null) return ['executed'=>false,'message'=>$strategyKey.': posiÃ§Ã£o monitorada.'];
        $grossReturn=($price/$state['entry']-1)*100;
        $netReturn=$grossReturn*($state['side']==='LONG'?1:-1)-$state['cost_pct'];
        $pnl=tsUnrealized($state,$price);$cash=max(0,$state['cash']+$pnl);
        $event=tsEvent($runId,$data,$state,$time);
        foreach (['entry_time','predicted_label','neighbors_count','similarity','probability_up','probability_flat','probability_down','model_version'] as $field) $event[$field]=$origin[$field];
        $event=array_replace($event,['event_type'=>'CLOSE_'.$state['side'],'side'=>$state['side'],
            'entry_price'=>$state['entry'],'btc_return_pct'=>$grossReturn,'trade_return_pct'=>$netReturn,
            'pnl_usd'=>$pnl,'balance_after'=>$cash,'cash_balance'=>$cash,'equity_after'=>$cash,
            'actual_label'=>spLabel($grossReturn,LIVE_THRESHOLD),
            'was_correct'=>(int)((int)$origin['predicted_label']===spLabel($grossReturn,LIVE_THRESHOLD)),
            'position_side'=>'FLAT','notes'=>$reason.' - '.$strategyKey.' - custos liquidados.',
            'cost_pct'=>$state['cost_pct'],'fees_usd'=>$state['entry']*$state['qty']*$state['cost_pct']/100]);
        tsInsert($pdo,$event);
        return ['executed'=>true,'message'=>$strategyKey.': '.$reason.'.'];
    }
    if ($origin && $time-strtotime($origin['exit_time'])<$intervalSeconds) return ['executed'=>false,'message'=>$strategyKey.': aguardando intervalo apÃ³s fechamento.'];
    if ($state['cash']<=0) return ['executed'=>false,'message'=>$strategyKey.': saldo zerado.'];
    if (!in_array($label,[0,2],true)) return ['executed'=>false,'message'=>$strategyKey.': '.$signal['reason']];
    $side=$label===2?'LONG':'SHORT';$qty=$state['cash']*$policy['allocation']/$price;
    $cost=tradeRoundTripCost($policy);$estimatedCosts=$qty*$price*$cost/100;
    $event=tsEvent($runId,$data,$state,$time,$label);
    tsInsert($pdo,array_replace($event,['event_type'=>'OPEN_'.$side,'side'=>$side,'position_side'=>$side,
        'position_qty'=>$qty,'position_entry_price'=>$price,'cost_pct'=>$cost,
        'equity_after'=>$state['cash']-$estimatedCosts,'unrealized_pnl_usd'=>-$estimatedCosts,
        'notes'=>$signal['reason'].'; 25% do saldo; custos provisionados.']));
    return ['executed'=>true,'message'=>$strategyKey.': abriu '.($side==='LONG'?'compra':'venda').'.'];
}

function tsExecute(PDO $pdo, int $intervalSeconds = 60, ?array $data = null): array
{
    // Cron and browser share the same lock and transaction. A quote cannot fill twice.
    $lock=fopen(__DIR__.'/data/trade_execution.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if($lock)fclose($lock);return ['ok'=>true,'executed'=>false,'message'=>'ExecuÃ§Ã£o em andamento.']; }
    try {
        $data=$data??spData(LIVE_HORIZON_MINUTES,LIVE_THRESHOLD,true);$runId=tsLiveRunId($pdo);$decision=$data['decision'];
        tradeSignalSave($data['prediction'],$data['latest'],['source'=>'trade_engine','run_id'=>$runId,
            'minutes'=>LIVE_HORIZON_MINUTES,'threshold'=>LIVE_THRESHOLD,'trade_label'=>$decision['label'],
            'model_version'=>TRADE_MODEL_VERSION,'notes'=>$decision['reason']]);
        if (empty($data['fresh']) || empty($data['latest'])) return ['ok'=>true,'executed'=>false,'message'=>$decision['reason']];
        $price=(float)$data['latest']['btc'];$time=(int)$data['latest']['time'];$policy=tradePolicy();
        $pdo->beginTransaction();$intervalSeconds=in_array($intervalSeconds,[60,300],true)?$intervalSeconds:60;$results=[];
        if (array_key_exists('history',$data)) {
            $history=$data['history'];$index=count($history)-1;
            foreach (tsStrategies() as $key=>$strategy) {
                $signal=tsStrategySignal($key,$history,$index,$data);
                $results[]=tsExecuteStrategy($pdo,$runId,$key,$signal,$data,$time,$price,$intervalSeconds,$policy);
            }
        } else {
            foreach (LIVE_LANES as $lane=>$side) $results[]=tsExecuteLane($pdo,$runId,$lane,$side,$data,$time,$price,$intervalSeconds,$policy);
        }
        $pdo->commit();
        return ['ok'=>true,'executed'=>in_array(true,array_column($results,'executed'),true),'message'=>implode(' ',array_column($results,'message'))];
    } catch (Throwable $error) {
        if($pdo->inTransaction())$pdo->rollBack();throw $error;
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}

function tsBacktestStrategy(string $strategyKey, array $data): array
{
    $history=$data['history']??[];$balance=LIVE_INITIAL_BALANCE;$orders=[];$wins=$losses=$skipped=0;$peak=$balance;$drawdown=0.0;$gain=$loss=0.0;
    if (!isset(LIVE_STRATEGIES[$strategyKey]) || count($history)<2) return ['initial'=>$balance,'balance'=>$balance,'pnl'=>0,'return_pct'=>0,'orders'=>[],'trades'=>0,'skipped'=>0,'wins'=>0,'losses'=>0,'win_rate'=>null,'source_tests'=>0,'drawdown'=>0,'profit_factor'=>null];
    $samples=spSamples($history,LIVE_HORIZON_MINUTES,LIVE_THRESHOLD);$indexes=[];
    foreach($history as $i=>$row)$indexes[(string)$row['time']]=$i;
    $adaptiveSignals=[];$testRows=$data['tests']??[];
    foreach($testRows as $test)$adaptiveSignals[(string)$test['time']]=(int)($test['tradeLabel']??1);
    // Compare every strategy over the same recent interval evaluated by Super Previsão.
    $evaluationStart=$testRows?min(array_map(static fn(array $test):int=>(int)$test['time'],$testRows)):((int)$history[count($history)-1]['time']-12*3600);
    $nextEntry=0;
    foreach($samples as $sample){
        if($sample['time']<$evaluationStart)continue;
        if($sample['time']<$nextEntry)continue;
        $index=$indexes[(string)$sample['time']]??null;
        if($index===null){$skipped++;continue;}
        $signal=tsStrategySignal($strategyKey,$history,$index,$data,$adaptiveSignals);$label=(int)$signal['label'];
        if(!in_array($label,[0,2],true)){$skipped++;continue;}
        $outcome=tradeReplayOutcome($history,$sample,$label);$before=$balance;
        $pnl=$before*tradePolicy()['allocation']*(float)$outcome['net']/100;
        $balance=max(0,$balance+$pnl);$peak=max($peak,$balance);$drawdown=max($drawdown,$peak>0?($peak-$balance)/$peak*100:0);
        $wins+=(int)($pnl>0);$losses+=(int)($pnl<0);$gain+=max(0,$pnl);$loss+=max(0,-$pnl);
        $actual=spLabel((float)$outcome['return'],LIVE_THRESHOLD);
        $orders[]=['time'=>$sample['time'],'end'=>$outcome['end'],'side'=>$label===2?'Compra':'Venda',
            'btc_return'=>(float)$outcome['return'],'trade_return'=>(float)$outcome['net'],'pnl'=>$pnl,
            'balance_before'=>$before,'balance_after'=>$balance,'correct'=>$label===$actual,'reason'=>$outcome['reason']];
        $nextEntry=(int)$outcome['end'];
    }
    $total=count($orders);
    return ['initial'=>LIVE_INITIAL_BALANCE,'balance'=>$balance,'pnl'=>$balance-LIVE_INITIAL_BALANCE,
        'return_pct'=>($balance/LIVE_INITIAL_BALANCE-1)*100,'orders'=>array_reverse($orders),
        'trades'=>$total,'skipped'=>$skipped,'wins'=>$wins,'losses'=>$losses,'win_rate'=>$total?$wins/$total:null,
        'source_tests'=>count($samples),'drawdown'=>$drawdown,'profit_factor'=>$loss>0?$gain/$loss:null];
}

function tsSnapshot(PDO $pdo, string $runId, string $message = '', ?array $data = null, string $selected = 'median'): array
{
    $data=$data??spData(LIVE_HORIZON_MINUTES,LIVE_THRESHOLD,true);$price=(float)($data['latest']['btc']??0);$history=$data['history']??[];
    $index=count($history)-1;$states=[];$cash=0.0;$unrealized=0.0;
    foreach(tsStrategies() as $key=>$strategy){
        $state=tsLastState($pdo,$runId,$key);$laneUnrealized=$price>0?tsUnrealized($state,$price):0;
        $state['unrealized']=$laneUnrealized;$state['equity']=$state['cash']+$laneUnrealized;
        $state['signal']=tsStrategySignal($key,$history,$index,$data);$state['name']=$strategy['name'];$states[$key]=$state;
        $cash+=$state['cash'];$unrealized+=$laneUnrealized;
    }
    if(!isset($states[$selected]))$selected='median';
    $stmt=$pdo->prepare('SELECT * FROM trade_simulado WHERE is_live=1 AND run_id=? AND strategy_lane=? ORDER BY id DESC LIMIT 80');
    $stmt->execute([$runId,$selected]);
    return ['run_id'=>$runId,'message'=>$message?:$data['decision']['reason'],'price'=>$price,
        'state'=>['side'=>$states[$selected]['side'],'cash'=>$cash,'lanes'=>$states,'selected'=>$states[$selected]],
        'unrealized'=>$unrealized,'equity'=>$cash+$unrealized,'prediction'=>$data['prediction']??null,
        'decision'=>$data['decision'],'evidence'=>$data['evidence'],'fresh'=>$data['fresh'],'policy'=>$data['policy'],
        'metrics'=>$data['metrics'],'latest'=>$data['latest'],'selected_strategy'=>$selected,'orders'=>$stmt->fetchAll()];
}

// Backwards-compatible baseline report for the original adaptive strategy.
function tsBacktest(array $data): array
{
    $balance=LIVE_INITIAL_BALANCE;$orders=[];$wins=$losses=$skipped=0;$peak=$balance;$drawdown=0;$policy=tradePolicy();$grossWins=$grossLosses=0.0;
    foreach($data['tests']??[] as $test){
        $label=$test['tradeLabel']??1;if($label===1){$skipped++;continue;}$before=$balance;$net=(float)$test['candidateNetPct'];
        $pnl=$before*$policy['allocation']*$net/100;$balance=max(0,$balance+$pnl);$peak=max($peak,$balance);
        $drawdown=max($drawdown,($peak-$balance)/$peak*100);$wins+=(int)($pnl>0);$losses+=(int)($pnl<0);
        $grossWins+=max(0,$pnl);$grossLosses+=max(0,-$pnl);
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
