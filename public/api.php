<?php

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/src/Logger.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/EGestorClient.php';
require_once dirname(__DIR__) . '/src/NuvemshopClient.php';
require_once dirname(__DIR__) . '/src/SyncService.php';
require_once dirname(__DIR__) . '/src/Auth.php';

use BiaMiddleware\Logger;
use BiaMiddleware\Database;
use BiaMiddleware\EGestorClient;
use BiaMiddleware\NuvemshopClient;
use BiaMiddleware\SyncService;
use BiaMiddleware\Auth;

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessão expirada ou não autenticada. Faça login novamente.']);
    exit;
}

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

            // Total em tempo real na Nuvemshop (sem depender de tabela de banco)
            $nuvemIndex = $syncService->getNuvemshopProductsIndex();
            $totalMapped = (int)($nuvemIndex['total'] ?? 0);
            $lastSync = $db->query("SELECT MAX(created_at) FROM sync_logs WHERE status = 'success'")->fetchColumn();
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
                ],
                'sync_only_with_stock' => $syncService->shouldSyncOnlyWithStock()
            ]);
            break;

        case 'products':
            $search = trim($_GET['search'] ?? '');
            $limit = min(300, max(10, (int)($_GET['limit'] ?? 100)));
            $stockFilter = $_GET['stock'] ?? 'all';
            $statusFilter = $_GET['status'] ?? 'all';

            $where = ["(status != 'removed_from_nuvem' OR status IS NULL)"];
            $params = [];

            if ($search !== '') {
                $where[] = "(name LIKE :q OR sku LIKE :q OR barcode LIKE :q)";
                $params[':q'] = "%{$search}%";
            }

            if ($stockFilter === 'has_stock') {
                $where[] = "stock_nuvemshop > 0";
            } elseif ($stockFilter === 'zero_stock') {
                $where[] = "stock_nuvemshop <= 0";
            } elseif ($stockFilter === 'low_stock') {
                $where[] = "stock_nuvemshop > 0 AND stock_nuvemshop <= 3";
            }

            if ($statusFilter !== 'all' && $statusFilter !== '') {
                $where[] = "status = :status";
                $params[':status'] = $statusFilter;
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $db->prepare("SELECT * FROM product_mappings WHERE {$whereSql} ORDER BY id DESC LIMIT :lim");
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, PDO::PARAM_STR);
            }
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $products = $stmt->fetchAll();
            echo json_encode(['success' => true, 'products' => $products, 'count' => count($products)]);
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
            $force = isset($_POST['force']) ? filter_var($_POST['force'], FILTER_VALIDATE_BOOLEAN) : (isset($_GET['force']) ? filter_var($_GET['force'], FILTER_VALIDATE_BOOLEAN) : false);

            $result = $syncService->syncProductFromEGestor($codigo, 'manual_send', $force);
            echo json_encode($result);
            break;

        case 'delete_nuvem_product':
            $productId = trim($_POST['id'] ?? $_GET['id'] ?? '');
            if ($productId === '') {
                echo json_encode(['success' => false, 'error' => 'ID do produto na Nuvemshop não informado']);
                exit;
            }
            $result = $syncService->removeProductFromNuvemshop($productId);
            echo json_encode($result);
            break;

        case 'egestor_vs_nuvem':
            $page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
            $filter = trim($_GET['filter'] ?? $_POST['filter'] ?? '');
            
            // Suporta status: 'all', 'missing', 'in_nuvem'
            $statusFilter = $_GET['status'] ?? $_POST['status'] ?? null;
            if ($statusFilter === null) {
                if (isset($_GET['only_missing']) || isset($_POST['only_missing'])) {
                    $om = !empty($_GET['only_missing']) || !empty($_POST['only_missing']);
                    $statusFilter = $om ? 'missing' : 'all';
                } else {
                    $statusFilter = 'missing';
                }
            }

            // Suporta stock: 'all', 'has_stock', 'zero_stock', 'low_stock'
            $stockFilter = $_GET['stock'] ?? $_POST['stock'] ?? 'all';

            $result = $syncService->getEGestorVsNuvemProducts($page, $filter, $statusFilter, $stockFilter);
            echo json_encode($result);
            break;

        case 'import_nuvemshop':
            $result = $syncService->importProductsFromNuvemshop();
            echo json_encode($result);
            break;

        case 'export_products_data':
            // Retorna produtos mapeados no banco local para exportação em Excel
            $statusFilter = $_GET['status'] ?? 'all';
            $stockFilter = $_GET['stock'] ?? 'all';
            $search = trim($_GET['filter'] ?? $_GET['search'] ?? '');

            $where = ["(status != 'removed_from_nuvem' OR status IS NULL)"];
            $params = [];

            if ($search !== '') {
                $where[] = "(name LIKE :q OR sku LIKE :q OR barcode LIKE :q OR egestor_id LIKE :q)";
                $params[':q'] = "%{$search}%";
            }

            if ($statusFilter === 'in_nuvem') {
                $where[] = "nuvemshop_product_id IS NOT NULL AND nuvemshop_product_id != ''";
            } elseif ($statusFilter === 'missing') {
                $where[] = "(nuvemshop_product_id IS NULL OR nuvemshop_product_id = '')";
            }

            if ($stockFilter === 'has_stock') {
                $where[] = "(stock_nuvemshop > 0 OR stock_egestor > 0)";
            } elseif ($stockFilter === 'zero_stock') {
                $where[] = "(stock_nuvemshop <= 0 AND stock_egestor <= 0)";
            } elseif ($stockFilter === 'low_stock') {
                $where[] = "((stock_nuvemshop > 0 AND stock_nuvemshop <= 3) OR (stock_egestor > 0 AND stock_egestor <= 3))";
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $db->prepare("SELECT * FROM product_mappings WHERE {$whereSql} ORDER BY id DESC");
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $products = [];
            foreach ($rows as $r) {
                $products[] = [
                    'codigo' => $r['egestor_id'] ?: $r['sku'],
                    'sku' => $r['sku'] ?: $r['egestor_id'],
                    'barcode' => $r['barcode'] ?: '',
                    'nome' => $r['name'],
                    'estoque' => (float)($r['stock_egestor'] ?? 0),
                    'stock_nuvemshop' => (float)($r['stock_nuvemshop'] ?? 0),
                    'preco' => (float)($r['price'] ?? 0),
                    'in_nuvemshop' => !empty($r['nuvemshop_product_id']),
                    'nuvemshop_product_id' => $r['nuvemshop_product_id'] ?: '',
                    'nuvemshop_variant_id' => $r['nuvemshop_variant_id'] ?: '',
                    'status' => $r['status'] ?: 'synced',
                    'last_sync_at' => $r['last_sync_at'] ?: ''
                ];
            }

            echo json_encode([
                'success' => true,
                'count' => count($products),
                'items' => $products
            ]);
            break;

        case 'export_egestor_full_catalog':
            // Varre as páginas do eGestor e retorna todos os produtos com dados fiscais para a planilha
            @set_time_limit(300);
            $filter = trim($_GET['filter'] ?? '');
            $statusFilter = $_GET['status'] ?? 'all';
            $stockFilter = $_GET['stock'] ?? 'all';

            $allProducts = [];
            $currentPage = 1;
            $maxPages = 60; // Suporta até 3.000 produtos por exportação

            while ($currentPage <= $maxPages) {
                $res = $syncService->getEGestorVsNuvemProducts($currentPage, $filter, $statusFilter, $stockFilter);
                if (!$res['success'] || empty($res['items'])) {
                    break;
                }

                foreach ($res['items'] as $item) {
                    $allProducts[] = $item;
                }

                if ($currentPage >= ($res['last_page'] ?? 1)) {
                    break;
                }
                $currentPage++;
            }

            echo json_encode([
                'success' => true,
                'count' => count($allProducts),
                'items' => $allProducts
            ]);
            break;

        case 'export_egestor_page':
            $page = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
            $onlyStock = null;
            if (isset($_POST['only_stock'])) {
                $onlyStock = filter_var($_POST['only_stock'], FILTER_VALIDATE_BOOLEAN);
            } elseif (isset($_GET['only_stock'])) {
                $onlyStock = filter_var($_GET['only_stock'], FILTER_VALIDATE_BOOLEAN);
            }
            @set_time_limit(120);
            $result = $syncService->exportPageFromEGestorToNuvemshop($page, $onlyStock);
            echo json_encode($result);
            break;

        case 'export_egestor_to_nuvem':
            // Importa todo o catálogo do eGestor e envia para a Nuvemshop
            @set_time_limit(600);
            $onlyStock = null;
            if (isset($_POST['only_stock'])) {
                $onlyStock = filter_var($_POST['only_stock'], FILTER_VALIDATE_BOOLEAN);
            } elseif (isset($_GET['only_stock'])) {
                $onlyStock = filter_var($_GET['only_stock'], FILTER_VALIDATE_BOOLEAN);
            }
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
            if (isset($_POST['sync_only_with_stock'])) {
                $syncOnlyWithStock = filter_var($_POST['sync_only_with_stock'], FILTER_VALIDATE_BOOLEAN);
                $syncService->setSyncOnlyWithStock($syncOnlyWithStock);
            }
            echo json_encode([
                'success' => true,
                'message' => 'Configurações salvas com sucesso!',
                'sync_only_with_stock' => $syncService->shouldSyncOnlyWithStock()
            ]);
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

        case 'create_full_product':
            // Cadastro completo de produto: cria na Nuvemshop (com grade) e separado no eGestor
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            if (empty($data)) {
                $data = $_POST;
            }

            $res = $syncService->createFullProduct($data);
            echo json_encode($res);
            break;

        case 'create_unified_product':
            // Cria um produto com grade de variações unificando produtos avulsos do eGestor
            @set_time_limit(180);
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            if (empty($data)) {
                $data = $_POST;
            }

            $res = $syncService->createUnifiedProduct($data);
            echo json_encode($res);
            break;

        case 'change_password':
            $rawInput = json_decode(file_get_contents('php://input'), true);
            $currentPass = $_POST['current_password'] ?? $rawInput['current_password'] ?? '';
            $newPass = $_POST['new_password'] ?? $rawInput['new_password'] ?? '';
            $user = Auth::user();

            if (!$user || empty($user['id'])) {
                echo json_encode(['success' => false, 'error' => 'Usuário não autenticado']);
                exit;
            }

            if (strlen($newPass) < 4) {
                echo json_encode(['success' => false, 'error' => 'A nova senha deve ter pelo menos 4 caracteres']);
                exit;
            }

            $stmtU = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmtU->execute([$user['id']]);
            $uData = $stmtU->fetch();

            if (!$uData || !password_verify($currentPass, $uData['password_hash'])) {
                echo json_encode(['success' => false, 'error' => 'Senha atual incorreta']);
                exit;
            }

            Auth::changePassword($user['id'], $newPass);
            echo json_encode(['success' => true, 'message' => 'Senha alterada com sucesso!']);
            break;

        case 'orders':
            // Lista pedidos sincronizados entre Nuvemshop e eGestor (NF-e)
            $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
            $stmt = $db->prepare("SELECT * FROM orders_sync ORDER BY id DESC LIMIT :lim");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $orders = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'count' => count($orders),
                'orders' => $orders
            ]);
            break;

        case 'sync_order':
            // Sincroniza manualmente um pedido da Nuvemshop no eGestor
            $orderId = trim($_POST['order_id'] ?? $_GET['order_id'] ?? '');
            if ($orderId === '') {
                echo json_encode(['success' => false, 'error' => 'ID do pedido não informado']);
                exit;
            }

            $orderRes = $nuvemshop->getOrder($orderId);
            if (!$orderRes['success'] || empty($orderRes['data'])) {
                echo json_encode(['success' => false, 'error' => $orderRes['error'] ?? 'Pedido não localizado na Nuvemshop']);
                exit;
            }

            $res = $syncService->syncOrderCustomerAndSaleToEGestor($orderRes['data'], $orderId);
            echo json_encode($res);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
