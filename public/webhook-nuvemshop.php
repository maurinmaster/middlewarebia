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

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true) ?: [];

// Nuvemshop pode enviar o evento no cabeçalho ou no payload
$event = $_SERVER['HTTP_X_LINKEDSTORE_EVENT'] ?? $payload['event'] ?? $_GET['event'] ?? 'order/created';

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nenhum payload recebido']);
    exit;
}

try {
    $egestor = new EGestorClient($config['egestor']);
    $nuvemshop = new NuvemshopClient($config['nuvemshop']);
    $syncService = new SyncService($egestor, $nuvemshop);

    $result = $syncService->handleNuvemshopWebhook($event, $payload);
    http_response_code(200);
    echo json_encode($result);
} catch (\Throwable $e) {
    Logger::error('Erro ao processar webhook Nuvemshop: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
