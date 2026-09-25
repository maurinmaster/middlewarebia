<?php

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/src/Logger.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/EGestorClient.php';
require_once dirname(__DIR__) . '/src/NuvemshopClient.php';
require_once dirname(__DIR__) . '/src/SyncService.php';

use BiaMiddleware\Logger;
use BiaMiddleware\Database;
use BiaMiddleware\EGestorClient;
use BiaMiddleware\NuvemshopClient;
use BiaMiddleware\SyncService;

$config = require dirname(__DIR__) . '/config/config.php';

// Captura payload (suporta JSON e form-data)
$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (empty($payload)) {
    $payload = $_POST;
}

if (empty($payload)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nenhum payload recebido na requisição.'
    ]);
    exit;
}

try {
    $egestor = new EGestorClient($config['egestor']);
    $nuvemshop = new NuvemshopClient($config['nuvemshop']);
    $syncService = new SyncService($egestor, $nuvemshop);

    $result = $syncService->handleEGestorWebhook($payload);

    if (!($result['success'] ?? false)) {
        http_response_code($result['code'] ?? 400);
    } else {
        http_response_code(200);
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    Logger::error('Erro fatal no webhook do eGestor: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno ao processar webhook: ' . $e->getMessage()
    ]);
}
