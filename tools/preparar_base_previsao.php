<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/previsao_motor.php';
$path = $argv[1] ?? '';
if (!is_file($path)) {
    fwrite(STDERR, "Uso: php tools/preparar_base_previsao.php arquivo.sql\n");
    exit(1);
}
$rows = [];
// Parse the supplied phpMyAdmin single-row INSERT format; never execute SQL.
foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
    if (!preg_match('/^INSERT INTO `indicador_historico` \((.+)\) VALUES\((.*)\);$/', $line, $match)) {
        continue;
    }
    preg_match_all('/`([^`]+)`/', $match[1], $columnMatches);
    $values = str_getcsv($match[2], ',', "'", '\\');
    if (count($values) !== count($columnMatches[1])) {
        throw new RuntimeException('INSERT com quantidade inesperada de colunas.');
    }
    $row = array_combine($columnMatches[1], $values);
    $clean = ['created_at' => $row['created_at'], 'interval_used' => $row['interval_used']];
    foreach (array_merge(['btc_price', 'eth_price'], array_map(static function (int $i): string {
        return sprintf('indicator_%02d', $i);
    }, range(1, 50))) as $key) {
        $clean[$key] = is_numeric($row[$key] ?? null) ? (float)$row[$key] : null;
    }
    $clean['indicator_names'] = json_decode(stripslashes($row['indicator_names']), true, 512, JSON_THROW_ON_ERROR);
    if (count($clean['indicator_names']) !== 50) {
        throw new RuntimeException('Esperados 50 nomes de indicadores.');
    }
    $rows[] = $clean;
}
if (!$rows) {
    throw new RuntimeException('Nenhum INSERT compativel encontrado.');
}
$directory = dirname(__DIR__) . '/data';
if (!is_dir($directory)) {
    mkdir($directory, 0775, true);
}
$payload = ['source' => basename($path), 'rows' => $rows];
file_put_contents($directory . '/super_previsao_base.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$prepared = spPrepare($rows);
$last = $prepared[count($prepared) - 1];
$distinct = [];
for ($i = 0; $i < 50; $i++) {
    if (count(array_unique(array_column(array_column($prepared, 'x'), $i))) > 1) {
        $distinct[] = $last['names'][$i];
    }
}
$report = ['records' => count($rows), 'first' => $prepared[0]['date'], 'last' => $last['date'],
    'hours' => round(($last['time'] - $prepared[0]['time']) / 3600, 2),
    'intervals' => array_values(array_unique(array_column($prepared, 'interval'))),
    'variable_indicators' => count($distinct), 'results' => []];
foreach ([5, 15, 30, 60] as $minutes) {
    $analysis = spAnalyze($prepared, $minutes, .1);
    $prediction = $analysis['prediction'];
    $report['results'][$minutes] = ['samples' => $analysis['samples'], 'validation' => $analysis['metrics'],
        'latest' => $prediction ? array_diff_key($prediction, ['neighbors' => true]) : null];
}
file_put_contents($directory . '/super_previsao_analise.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
