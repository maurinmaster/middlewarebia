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
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$db = Database::getConnection();
$egestor = new EGestorClient($config['egestor']);
$nuvemshop = new NuvemshopClient($config['nuvemshop']);
$syncService = new SyncService($egestor, $nuvemshop);

try {
    switch ($action) {
        case 'status':
            $nuvemStatus = $nuvemshop->testConnection();
            $egestorStatus = $egestor->testConnection();

            $totalMapped = (int)$db->query("SELECT COUNT(*) FROM product_mappings")->fetchColumn();
            $lastSync = $db->query("SELECT MAX(last_sync_at) FROM product_mappings")->fetchColumn();
            $recentErrors = (int)$db->query("SELECT COUNT(*) FROM sync_logs WHERE status = 'error' AND created_at >= datetime('now', '-24 hours')")->fetchColumn();

            echo json_encode([
                'success' => true,
                'nuvemshop' => [
                    'connected' => $nuvemStatus['success'] ?? false,
                    'store_name' => $nuvemStatus['data']['name']['pt'] ?? null,
                    'store_id' => $config['nuvemshop']['store_id'],
                    'error' => $nuvemStatus['error'] ?? null
                ],
                'egestor' => [
                    'connected' => $egestorStatus['success'] ?? false,
                    'company_name' => $egestorStatus['data']['nome'] ?? null,
                    'has_token' => !empty($egestor->getPersonalToken()),
                    'error' => $egestorStatus['error'] ?? null
                ],
                'stats' => [
                    'total_mapped' => $totalMapped,
                    'last_sync' => $lastSync,
                    'errors_24h' => $recentErrors
                ]
            ]);
            break;

        case 'products':
            $search = trim($_GET['search'] ?? '');
            $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));

            if ($search !== '') {
                $stmt = $db->prepare("SELECT * FROM product_mappings WHERE name LIKE :q OR sku LIKE :q OR barcode LIKE :q ORDER BY id DESC LIMIT :lim");
                $stmt->bindValue(':q', "%{$search}%", PDO::PARAM_STR);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $stmt = $db->prepare("SELECT * FROM product_mappings ORDER BY id DESC LIMIT :lim");
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
            }

            $products = $stmt->fetchAll();
            echo json_encode(['success' => true, 'products' => $products]);
            break;

        case 'logs':
            $stmt = $db->query("SELECT * FROM sync_logs ORDER BY id DESC LIMIT 50");
            $logs = $stmt->fetchAll();
            $fileLogs = Logger::getRecentLogs(30);

            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'file_logs' => $fileLogs
            ]);
            break;

        case 'sync_product':
            $codigo = trim($_POST['codigo'] ?? $_GET['codigo'] ?? '');
            if ($codigo === '') {
                echo json_encode(['success' => false, 'error' => 'Código do produto não informado']);
                exit;
            }

            $result = $syncService->syncProductFromEGestor($codigo);
            echo json_encode($result);
            break;

        case 'import_nuvemshop':
            $result = $syncService->importProductsFromNuvemshop();
            echo json_encode($result);
            break;

        case 'export_egestor_page':
            $page = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
            $onlyStock = !empty($_POST['only_stock']) || !empty($_GET['only_stock']);
            @set_time_limit(120);
            $result = $syncService->exportPageFromEGestorToNuvemshop($page, $onlyStock);
            echo json_encode($result);
            break;

        case 'export_egestor_to_nuvem':
            // Importa todo o catálogo do eGestor e envia para a Nuvemshop
            @set_time_limit(600);
            $onlyStock = !empty($_POST['only_stock']) || !empty($_GET['only_stock']);
            $result = $syncService->exportAllFromEGestorToNuvemshop(null, $onlyStock);
            echo json_encode($result);
            break;

        case 'register_nuvemshop_webhooks':
            $webhookUrl = trim($_POST['url'] ?? '');
            if (empty($webhookUrl)) {
                $envUrl = getenv('APP_URL');
                $webhookUrl = rtrim($envUrl ?: 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/') . '/webhook-nuvemshop.php';
            }

            $events = ['order/created', 'order/paid', 'order/cancelled', 'product/updated'];
            $existing = $nuvemshop->listWebhooks();
            $existingUrls = [];
            if ($existing['success'] && is_array($existing['data'])) {
                foreach ($existing['data'] as $w) {
                    $existingUrls[$w['event'] . '|' . $w['url']] = $w['id'];
                }
            }

            $createdCount = 0;
            foreach ($events as $event) {
                $key = $event . '|' . $webhookUrl;
                if (!isset($existingUrls[$key])) {
                    $res = $nuvemshop->registerWebhook($event, $webhookUrl);
                    if ($res['success']) {
                        $createdCount++;
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Webhooks registrados na Nuvemshop com sucesso! ({$createdCount} novos eventos cadastrados para {$webhookUrl})",
                'webhook_url' => $webhookUrl
            ]);
            break;

        case 'save_settings':
            $personalToken = trim($_POST['egestor_personal_token'] ?? '');
            if ($personalToken !== '') {
                $egestor->setPersonalToken($personalToken);
            }
            echo json_encode(['success' => true, 'message' => 'Configurações salvas com sucesso!']);
            break;

        case 'test_webhook':
            // Simula um webhook do eGestor
            $testCodigo = trim($_POST['codigo'] ?? '1');
            $simulatedPayload = [
                'action' => 'updated',
                'codigo' => $testCodigo,
                'module' => 'produtos',
                'securityToken' => $config['egestor']['security_token'],
                'date' => date('Y-m-d H:i:s')
            ];

            $result = $syncService->handleEGestorWebhook($simulatedPayload);
            echo json_encode($result);
            break;

        case 'create_product_nuvem':
            // Cria um produto diretamente na Nuvemshop
            $name = trim($_POST['name'] ?? '');
            $sku = trim($_POST['sku'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $stock = (int)($_POST['stock'] ?? 0);
            $barcode = trim($_POST['barcode'] ?? '');

            if ($name === '') {
                echo json_encode(['success' => false, 'error' => 'Nome do produto é obrigatório']);
                exit;
            }

            $createRes = $nuvemshop->createProduct([
                'name' => $name,
                'sku' => $sku,
                'price' => $price,
                'stock' => $stock,
                'barcode' => $barcode
            ]);

            if ($createRes['success'] && !empty($createRes['data']['id'])) {
                $prodId = $createRes['data']['id'];
                $varId = $createRes['data']['variants'][0]['id'] ?? null;

                $db->prepare("INSERT INTO product_mappings (nuvemshop_product_id, nuvemshop_variant_id, sku, barcode, name, stock_nuvemshop, price, status, last_sync_at, last_sync_direction)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'synced', CURRENT_TIMESTAMP, 'manual_create')")
                    ->execute([$prodId, $varId, $sku, $barcode, $name, $stock, $price]);

                Database::logSync('manual', 'product_created', (string)$prodId, "Produto '{$name}' criado na Nuvemshop com estoque {$stock}", 'success');
                echo json_encode(['success' => true, 'product' => $createRes['data']]);
            } else {
                echo json_encode(['success' => false, 'error' => $createRes['error'] ?? 'Erro desconhecido']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
