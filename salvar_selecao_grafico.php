<?php
declare(strict_types=1);

require_once __DIR__ . '/indicador_settings.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Metodo nao permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

try {
    $selection = indicadorSaveChartSelection($payload['selected_series'] ?? []);
    echo json_encode(['ok' => true, 'selection' => $selection], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
