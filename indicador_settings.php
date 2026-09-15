<?php
declare(strict_types=1);

const INDICADOR_SETTINGS_FILE = __DIR__ . '/indicador_config.json';
const INDICADOR_CHART_SELECTION_FILE = __DIR__ . '/indicador_grafico_selecao.json';
const INDICADOR_ALLOWED_SAVE_INTERVALS = [1, 5, 15, 30, 60];

function indicadorSettings(): array
{
    $settings = [
        'save_interval_minutes' => 1,
        'allowed_save_interval_minutes' => INDICADOR_ALLOWED_SAVE_INTERVALS,
        'updated_at' => null,
    ];

    if (is_file(INDICADOR_SETTINGS_FILE)) {
        $json = file_get_contents(INDICADOR_SETTINGS_FILE);
        $decoded = $json === false ? null : json_decode($json, true);
        if (is_array($decoded)) {
            $settings = array_replace($settings, $decoded);
        }
    }

    $minutes = (int)($settings['save_interval_minutes'] ?? 1);
    if (!in_array($minutes, INDICADOR_ALLOWED_SAVE_INTERVALS, true)) {
        $minutes = 1;
    }

    $settings['save_interval_minutes'] = $minutes;
    $settings['allowed_save_interval_minutes'] = INDICADOR_ALLOWED_SAVE_INTERVALS;

    return $settings;
}

function indicadorSaveIntervalMinutes(): int
{
    return (int)indicadorSettings()['save_interval_minutes'];
}

function indicadorSaveSettings(int $saveIntervalMinutes): array
{
    if (!in_array($saveIntervalMinutes, INDICADOR_ALLOWED_SAVE_INTERVALS, true)) {
        $saveIntervalMinutes = 1;
    }

    $settings = [
        'save_interval_minutes' => $saveIntervalMinutes,
        'allowed_save_interval_minutes' => INDICADOR_ALLOWED_SAVE_INTERVALS,
        'updated_at' => date('c'),
    ];

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Nao foi possivel gerar o JSON de configuracao.');
    }

    $fp = fopen(INDICADOR_SETTINGS_FILE, 'c+');
    if ($fp === false) {
        throw new RuntimeException('Nao foi possivel abrir indicador_config.json.');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('Nao foi possivel bloquear indicador_config.json.');
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json . PHP_EOL);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    return $settings;
}

function indicadorChartSelection(): array
{
    $selection = [
        'selected_series' => [],
        'updated_at' => null,
    ];

    if (is_file(INDICADOR_CHART_SELECTION_FILE)) {
        $json = file_get_contents(INDICADOR_CHART_SELECTION_FILE);
        $decoded = $json === false ? null : json_decode($json, true);
        if (is_array($decoded)) {
            $selection = array_replace($selection, $decoded);
        }
    }

    $series = $selection['selected_series'] ?? [];
    if (!is_array($series)) {
        $series = [];
    }

    $selection['selected_series'] = array_values(array_filter(
        array_map('strval', $series),
        static fn(string $key): bool => preg_match('/^(btc_price|eth_price|indicator_[0-9]{2})$/', $key) === 1
    ));

    return $selection;
}

function indicadorSaveChartSelection(array $selectedSeries): array
{
    $selectedSeries = array_values(array_unique(array_filter(
        array_map('strval', $selectedSeries),
        static fn(string $key): bool => preg_match('/^(btc_price|eth_price|indicator_[0-9]{2})$/', $key) === 1
    )));

    $selection = [
        'selected_series' => $selectedSeries,
        'updated_at' => date('c'),
    ];

    $json = json_encode($selection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Nao foi possivel gerar o JSON da selecao do grafico.');
    }

    $fp = fopen(INDICADOR_CHART_SELECTION_FILE, 'c+');
    if ($fp === false) {
        throw new RuntimeException('Nao foi possivel abrir indicador_grafico_selecao.json.');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('Nao foi possivel bloquear indicador_grafico_selecao.json.');
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json . PHP_EOL);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    return $selection;
}
