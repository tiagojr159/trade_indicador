<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/previsao_motor.php';

const SIM_INITIAL_BALANCE = 100.0;
const SIM_THRESHOLD = 0.1;
const SIM_MAX_ROWS = 3000;

function simPdo(): PDO
{
    $pdo = appPdo();
    $schema = file_get_contents(__DIR__ . '/criar_tabela_trade_simulado.sql');
    if ($schema === false) {
        throw new RuntimeException('Arquivo criar_tabela_trade_simulado.sql nao encontrado.');
    }
    $pdo->exec($schema);
    return $pdo;
}

function simPreparedRows(PDO $pdo): array
{
    $rows = array_reverse($pdo->query('SELECT * FROM indicador_historico ORDER BY created_at DESC, id DESC LIMIT ' . SIM_MAX_ROWS)->fetchAll());
    return spPrepare($rows);
}

function simRunBacktest(array $prepared, int $minutes, float $threshold): array
{
    if (!$prepared) {
        return ['minutes' => $minutes, 'threshold' => $threshold, 'trades' => [], 'balance' => SIM_INITIAL_BALANCE,
            'wins' => 0, 'losses' => 0, 'skipped' => 0, 'accuracy' => null];
    }
    $latest = $prepared[count($prepared) - 1];
    $rows = array_values(array_filter($prepared, static function (array $row) use ($latest): bool {
        return $row['interval'] === $latest['interval'] && $row['names'] === $latest['names'];
    }));
    $samples = spSamples($rows, $minutes, $threshold);
    $config = spPredictionConfig($minutes, $threshold);
    $balance = SIM_INITIAL_BALANCE;
    $trades = [];
    $wins = $losses = $skipped = $tested = $correct = 0;
    $nextTrade = 0;

    foreach ($samples as $sample) {
        if ($sample['time'] < $nextTrade) {
            continue;
        }
        $prediction = spPredict($samples, $sample, $config);
        if ($prediction === null) {
            $skipped++;
            continue;
        }
        $tested++;
        $correct += (int)($prediction['label'] === $sample['label']);
        if ($prediction['label'] === 1) {
            $skipped++;
            continue;
        }

        $entry = null;
        $exit = null;
        foreach ($rows as $row) {
            if ($row['time'] === $sample['time']) {
                $entry = $row;
            }
            if ($row['time'] === $sample['end']) {
                $exit = $row;
            }
        }
        if (!$entry || !$exit) {
            $skipped++;
            continue;
        }

        $side = $prediction['label'] === 2 ? 'LONG' : 'SHORT';
        $btcReturn = ($exit['btc'] / $entry['btc'] - 1) * 100;
        $tradeReturn = $side === 'LONG' ? $btcReturn : -$btcReturn;
        $before = $balance;
        $pnl = $before * ($tradeReturn / 100);
        $balance = max(0.0, $before + $pnl);
        $win = $pnl > 0;
        $wins += (int)$win;
        $losses += (int)!$win;
        $nextTrade = $sample['end'];

        $trades[] = [
            'minutes' => $minutes,
            'threshold' => $threshold,
            'balance_before' => $before,
            'balance_after' => $balance,
            'entry_time' => date('Y-m-d H:i:s', $sample['time']),
            'exit_time' => date('Y-m-d H:i:s', $sample['end']),
            'side' => $side,
            'entry_price' => $entry['btc'],
            'exit_price' => $exit['btc'],
            'btc_return_pct' => $btcReturn,
            'trade_return_pct' => $tradeReturn,
            'pnl_usd' => $pnl,
            'predicted_label' => $prediction['label'],
            'actual_label' => $sample['label'],
            'was_correct' => $prediction['label'] === $sample['label'],
            'neighbors_count' => $prediction['count'],
            'similarity' => $prediction['similarity'],
            'probability_up' => $prediction['frequencies'][2],
            'probability_flat' => $prediction['frequencies'][1],
            'probability_down' => $prediction['frequencies'][0],
        ];
    }

    return ['minutes' => $minutes, 'threshold' => $threshold, 'trades' => $trades, 'balance' => $balance,
        'wins' => $wins, 'losses' => $losses, 'skipped' => $skipped, 'tested' => $tested,
        'accuracy' => $tested ? $correct / $tested : null];
}

function simChooseBest(array $prepared): array
{
    $runs = [];
    foreach ([5, 10, 15] as $minutes) {
        $runs[] = simRunBacktest($prepared, $minutes, SIM_THRESHOLD);
    }
    usort($runs, static function (array $a, array $b): int {
        return ($b['balance'] <=> $a['balance']) ?: (count($b['trades']) <=> count($a['trades']));
    });
    return $runs[0];
}

function simSaveRun(PDO $pdo, array $run): string
{
    $runId = date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $sql = 'INSERT INTO trade_simulado (run_id, strategy_minutes, threshold_pct, initial_balance, balance_before, balance_after,
        entry_time, exit_time, side, entry_price, exit_price, btc_return_pct, trade_return_pct, pnl_usd, predicted_label,
        actual_label, was_correct, neighbors_count, similarity, probability_up, probability_flat, probability_down, notes)
        VALUES (:run_id, :strategy_minutes, :threshold_pct, :initial_balance, :balance_before, :balance_after,
        :entry_time, :exit_time, :side, :entry_price, :exit_price, :btc_return_pct, :trade_return_pct, :pnl_usd,
        :predicted_label, :actual_label, :was_correct, :neighbors_count, :similarity, :probability_up,
        :probability_flat, :probability_down, :notes)';
    $stmt = $pdo->prepare($sql);
    foreach ($run['trades'] as $trade) {
        $stmt->execute([
            ':run_id' => $runId,
            ':strategy_minutes' => $trade['minutes'],
            ':threshold_pct' => $trade['threshold'],
            ':initial_balance' => SIM_INITIAL_BALANCE,
            ':balance_before' => $trade['balance_before'],
            ':balance_after' => $trade['balance_after'],
            ':entry_time' => $trade['entry_time'],
            ':exit_time' => $trade['exit_time'],
            ':side' => $trade['side'],
            ':entry_price' => $trade['entry_price'],
            ':exit_price' => $trade['exit_price'],
            ':btc_return_pct' => $trade['btc_return_pct'],
            ':trade_return_pct' => $trade['trade_return_pct'],
            ':pnl_usd' => $trade['pnl_usd'],
            ':predicted_label' => $trade['predicted_label'],
            ':actual_label' => $trade['actual_label'],
            ':was_correct' => $trade['was_correct'] ? 1 : 0,
            ':neighbors_count' => $trade['neighbors_count'],
            ':similarity' => $trade['similarity'],
            ':probability_up' => $trade['probability_up'],
            ':probability_flat' => $trade['probability_flat'],
            ':probability_down' => $trade['probability_down'],
            ':notes' => 'Simulacao historica sem taxas, sem slippage e sem ordem real.',
        ]);
    }
    return $runId;
}

function simLatestRunId(PDO $pdo): ?string
{
    $row = $pdo->query('SELECT run_id FROM trade_simulado ORDER BY created_at DESC, id DESC LIMIT 1')->fetch();
    return $row ? (string)$row['run_id'] : null;
}

function simLoadRun(PDO $pdo, ?string $runId): array
{
    if ($runId === null) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM trade_simulado WHERE run_id = :run_id ORDER BY entry_time ASC, id ASC');
    $stmt->execute([':run_id' => $runId]);
    return $stmt->fetchAll();
}

function money(float $value): string
{
    return '$ ' . number_format($value, 2, ',', '.');
}

function pct(float $value): string
{
    return ($value > 0 ? '+' : '') . number_format($value, 2, ',', '.') . '%';
}

$pdo = simPdo();
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $run = simChooseBest(simPreparedRows($pdo));
    $runId = simSaveRun($pdo, $run);
    $message = 'Simulacao criada com ' . count($run['trades']) . ' trades usando horizonte de ' . $run['minutes'] . ' minutos.';
} else {
    $runId = simLatestRunId($pdo);
    if ($runId === null) {
        $run = simChooseBest(simPreparedRows($pdo));
        $runId = simSaveRun($pdo, $run);
        $message = 'Primeira simulacao criada automaticamente.';
    }
}

$trades = simLoadRun($pdo, $runId);
$last = $trades ? $trades[count($trades) - 1] : null;
$initial = $trades ? (float)$trades[0]['initial_balance'] : SIM_INITIAL_BALANCE;
$final = $last ? (float)$last['balance_after'] : $initial;
$wins = count(array_filter($trades, static fn(array $row): bool => (float)$row['pnl_usd'] > 0));
$losses = count($trades) - $wins;
$strategyMinutes = $trades ? (int)$trades[0]['strategy_minutes'] : 0;
$totalPnl = $final - $initial;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trade Simulado | Sistema Bitcoin</title>
<style>
:root{color-scheme:dark;--bg:#111416;--panel:#191e21;--line:#30373b;--text:#f0f3f4;--muted:#a3afb4;--accent:#f7b928;--up:#3cdda5;--down:#ff7182;--flat:#bdc6cc}
*{box-sizing:border-box;letter-spacing:0}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Arial,sans-serif}.wrap{max-width:1480px;margin:auto;padding:26px 28px}.topbar,.brand,nav,.cards,.section-head{display:flex;align-items:center}.topbar{justify-content:space-between;gap:22px;margin-bottom:28px}.brand{gap:12px}.coin{width:42px;height:42px;border-radius:8px;background:var(--accent);color:#151515;display:grid;place-items:center;font-size:26px;font-weight:900;text-decoration:none}h1{font-size:21px;line-height:1.2;margin:0 0 3px}small{color:var(--muted)}nav{flex-wrap:wrap;gap:5px}nav a{font-size:12px;text-decoration:none;color:var(--muted);padding:8px 10px;border-radius:6px}nav a[aria-current]{background:var(--accent);color:#151515;font-weight:750}nav a:hover{color:var(--text);background:#2b3235}.hero{border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:24px 0;display:grid;grid-template-columns:1fr auto;gap:24px;align-items:center}.hero h2{font-size:30px;margin:0 0 8px}.hero p{margin:0;color:var(--muted);max-width:760px}.button{border:0;border-radius:6px;background:var(--accent);color:#151515;font-weight:800;padding:12px 16px;cursor:pointer}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin:24px 0}.card{border:1px solid var(--line);background:var(--panel);border-radius:8px;padding:18px}.card span{display:block;color:var(--muted);font-size:12px}.card b{display:block;font-size:28px;margin-top:6px}.up{color:var(--up)}.down{color:var(--down)}.flat{color:var(--flat)}.notice{border-left:3px solid var(--accent);background:#282313;padding:12px;margin:18px 0;color:#ffe6a7}.section-head{justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:12px;margin-top:28px}h3{margin:0;font-size:17px}.table-scroll{overflow:auto;max-height:560px}table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}th{text-align:left;color:var(--muted);font-weight:600;position:sticky;top:0;background:var(--bg)}td,th{padding:10px;border-bottom:1px solid #292f33}td:last-child,th:last-child{text-align:right}.badge{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:999px;padding:3px 8px;color:var(--muted);font-size:12px}.empty{padding:26px;color:var(--muted)}@media(max-width:850px){.wrap{padding:18px 14px}.topbar{align-items:flex-start;flex-direction:column}.hero{grid-template-columns:1fr}.cards{grid-template-columns:1fr 1fr}.hero h2{font-size:25px}}@media(max-width:520px){.cards{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
<header class="topbar">
  <div class="brand"><a class="coin" href="index.php" aria-label="Sistema Bitcoin">B</a><div><h1>Trade Simulado</h1><small>Bitcoin / USDT</small></div></div>
  <nav aria-label="Principal"><a href="index.php">Capa</a><a href="indicadores.php">Indicadores</a><a href="historico_indicadores.php">Histórico com gráfico</a><a href="graficos_selecionados.php">Gráficos selecionados</a><a href="super_previsao.php">Super Previsão</a><a href="trade_simulado.php" aria-current="page">Trade simulado</a></nav>
</header>
<main>
  <section class="hero">
    <div><h2>Simulação de trades com as previsões</h2><p>Começa com US$ 100, entra comprado quando a previsão é alta, vendido quando é baixa, e fecha no horizonte escolhido. É apenas backtest local: não envia ordem real, não simula taxa, spread ou slippage.</p></div>
    <form method="post"><button class="button" type="submit">Gerar nova simulação</button></form>
  </section>
  <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <section class="cards" aria-label="Resumo">
    <div class="card"><span>Saldo inicial</span><b><?= money($initial) ?></b></div>
    <div class="card"><span>Saldo final</span><b class="<?= $totalPnl >= 0 ? 'up' : 'down' ?>"><?= money($final) ?></b></div>
    <div class="card"><span>Resultado</span><b class="<?= $totalPnl >= 0 ? 'up' : 'down' ?>"><?= money($totalPnl) ?></b></div>
    <div class="card"><span>Estratégia escolhida</span><b><?= $strategyMinutes ? $strategyMinutes . ' min' : '--' ?></b></div>
    <div class="card"><span>Trades</span><b><?= count($trades) ?></b></div>
    <div class="card"><span>Acertos</span><b class="up"><?= $wins ?></b></div>
    <div class="card"><span>Erros</span><b class="down"><?= $losses ?></b></div>
    <div class="card"><span>Taxa de acerto</span><b><?= $trades ? number_format($wins / count($trades) * 100, 1, ',', '.') . '%' : '--' ?></b></div>
  </section>
  <section>
    <div class="section-head"><h3>Registro de compra e venda simulada</h3><span class="badge">run <?= htmlspecialchars((string)$runId, ENT_QUOTES, 'UTF-8') ?></span></div>
    <?php if (!$trades): ?>
      <div class="empty">Nenhum trade simulado foi gerado.</div>
    <?php else: ?>
      <div class="table-scroll"><table>
        <thead><tr><th>Entrada</th><th>Saída</th><th>Lado</th><th>Preço entrada</th><th>Preço saída</th><th>BTC</th><th>Trade</th><th>Saldo antes</th><th>Saldo depois</th><th>Resultado</th><th>Previsão</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($trades) as $row): ?>
          <?php $pnl = (float)$row['pnl_usd']; $side = (string)$row['side']; ?>
          <tr>
            <td><?= date('d/m H:i', strtotime((string)$row['entry_time'])) ?></td>
            <td><?= date('d/m H:i', strtotime((string)$row['exit_time'])) ?></td>
            <td class="<?= $side === 'LONG' ? 'up' : 'down' ?>"><?= $side === 'LONG' ? 'Compra' : 'Venda' ?></td>
            <td><?= number_format((float)$row['entry_price'], 2, ',', '.') ?></td>
            <td><?= number_format((float)$row['exit_price'], 2, ',', '.') ?></td>
            <td class="<?= (float)$row['btc_return_pct'] >= 0 ? 'up' : 'down' ?>"><?= pct((float)$row['btc_return_pct']) ?></td>
            <td class="<?= (float)$row['trade_return_pct'] >= 0 ? 'up' : 'down' ?>"><?= pct((float)$row['trade_return_pct']) ?></td>
            <td><?= money((float)$row['balance_before']) ?></td>
            <td><?= money((float)$row['balance_after']) ?></td>
            <td class="<?= $pnl >= 0 ? 'up' : 'down' ?>"><?= money($pnl) ?></td>
            <td><?= $row['predicted_label'] == 2 ? 'Alta' : 'Baixa' ?> / real <?= ['Baixa','Lateral','Alta'][(int)$row['actual_label']] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>
</main>
</div>
</body>
</html>
