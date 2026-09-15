<?php
declare(strict_types=1);

$seconds = isset($argv[1]) ? max(60, (int)$argv[1]) : 3600;
$interval = $argv[2] ?? '1h';

echo "Agendador iniciado. Coleta a cada {$seconds} segundos usando candles {$interval}.\n";
echo "Para parar, feche esta janela ou pressione Ctrl+C.\n\n";

while (true) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/coletar_indicadores.php') . ' ' . escapeshellarg($interval);
    $result = trim((string)shell_exec($cmd));
    echo '[' . date('Y-m-d H:i:s') . "] {$result}\n";
    sleep($seconds);
}
