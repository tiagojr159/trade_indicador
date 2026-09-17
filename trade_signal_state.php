<?php
declare(strict_types=1);

if (!defined('TRADE_SIGNAL_STATE_FILE')) define('TRADE_SIGNAL_STATE_FILE', __DIR__ . '/data/trade_signal_state.json');

function tradeSignalLabel(int $label): string
{
    return $label === 2 ? 'alta' : ($label === 0 ? 'baixa' : 'lateral');
}

function tradeSignalSave(?array $prediction, ?array $latest, array $extra = []): array
{
    $label = $prediction ? (int)($prediction['label'] ?? 1) : 1;
    $frequencies = $prediction['frequencies'] ?? [0, 0, 0];
    $state = [
        'updated_at' => date('c'),
        'updated_ts' => time(),
        'source' => (string)($extra['source'] ?? 'unknown'),
        'run_id' => $extra['run_id'] ?? null,
        'minutes' => $extra['minutes'] ?? null,
        'threshold' => $extra['threshold'] ?? null,
        'label' => $label,
        'signal' => tradeSignalLabel($label),
        'is_directional' => $label !== 1,
        'trade_label' => $extra['trade_label'] ?? null,
        'trade_signal' => isset($extra['trade_label']) ? tradeSignalLabel((int)$extra['trade_label']) : null,
        'model_version' => $extra['model_version'] ?? null,
        'btc_price' => $latest ? (float)($latest['btc'] ?? 0) : null,
        'signal_time' => $latest && isset($latest['time']) ? date('c', (int)$latest['time']) : null,
        'signal_ts' => $latest['time'] ?? null,
        'probability_down' => isset($frequencies[0]) ? (float)$frequencies[0] : null,
        'probability_flat' => isset($frequencies[1]) ? (float)$frequencies[1] : null,
        'probability_up' => isset($frequencies[2]) ? (float)$frequencies[2] : null,
        'neighbors_count' => $prediction['count'] ?? null,
        'similarity' => $prediction['similarity'] ?? null,
        'notes' => $extra['notes'] ?? null,
    ];

    $dir = dirname(TRADE_SIGNAL_STATE_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents(TRADE_SIGNAL_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    return $state;
}

function tradeSignalRead(): ?array
{
    if (!is_file(TRADE_SIGNAL_STATE_FILE)) {
        return null;
    }
    $handle = fopen(TRADE_SIGNAL_STATE_FILE, 'r');
    if (!$handle) return null;
    flock($handle, LOCK_SH);
    $data = json_decode((string)stream_get_contents($handle), true);
    flock($handle, LOCK_UN);
    fclose($handle);
    return is_array($data) ? $data : null;
}
