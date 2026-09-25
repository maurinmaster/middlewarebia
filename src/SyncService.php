<?php

namespace BiaMiddleware;

use PDO;

class SyncService {
    private EGestorClient $egestor;
    private NuvemshopClient $nuvemshop;

    public function __construct(EGestorClient $egestor, NuvemshopClient $nuvemshop) {
        $this->egestor = $egestor;
        $this->nuvemshop = $nuvemshop;
    }

    /**
     * Processa webhook recebido do eGestor (PDV)
     */
    public function handleEGestorWebhook(array $payload): array {
        Logger::info('Webhook eGestor recebido', $payload);

        $securityToken = $payload['securityToken'] ?? null;
        if (!$this->egestor->validateWebhookToken($securityToken)) {
            $msg = 'Security token do eGestor inválido ou não informado.';
            Logger::warning($msg, ['received' => $securityToken]);
            Database::logSync('egestor', 'webhook_rejected', $payload['codigo'] ?? '?', $msg, 'error');
            return ['success' => false, 'error' => $msg, 'code' => 403];
        }

        $module = strtolower(trim($payload['module'] ?? ''));
        $action = strtolower(trim($payload['action'] ?? ''));
        $codigo = trim((string)($payload['codigo'] ?? ''));

        if ($codigo === '') {
            return ['success' => false, 'error' => 'Código do registro ausente no payload.', 'code' => 400];
        }

        if ($module === 'produtos') {
            return $this->syncProductFromEGestor($codigo, $action);
        }

        if ($module === 'vendas') {
            return $this->syncSaleFromEGestor($codigo);
        }

        Database::logSync('egestor', "module_{$module}", $codigo, "Evento ignorado (módulo {$module} não requer sync de estoque)", 'warning');
        return ['success' => true, 'message' => "Módulo {$module} recebido com sucesso (sem ação necessária)"];
    }

    /**
     * Sincroniza um produto específico do eGestor para a Nuvemshop
     */
    public function syncProductFromEGestor(string $codigo, string $action = 'updated'): array {
        try {
            // Busca dados atuais do produto no eGestor
            $res = $this->egestor->getProduct($codigo);
            if (!$res['success'] || empty($res['data'])) {
                $err = $res['error'] ?? 'Produto não encontrado na API do eGestor';
                Database::logSync('egestor', 'product_fetch_failed', $codigo, $err, 'error');
                return ['success' => false, 'error' => $err];
            }

            $prod = $res['data'];
            $nome        = trim($prod['descricao'] ?? 'Produto ' . $codigo);
            $sku         = trim($prod['codigoProprio'] ?? (string)$codigo);
            $barcode     = trim($prod['refEanGtin'] ?? '');
            $estoque     = (float)($prod['estoque'] ?? 0);
            $precoVenda  = (float)($prod['precoVenda'] ?? 0);

            $db = Database::getConnection();

            // 1. Tenta achar mapeamento existente no banco local
            $stmt = $db->prepare("SELECT * FROM product_mappings WHERE egestor_id = ? OR (sku != '' AND sku = ?) LIMIT 1");
            $stmt->execute([$codigo, $sku]);
            $mapping = $stmt->fetch();

            $nuvemProductId = null;
            $nuvemVariantId = null;

            if ($mapping && !empty($mapping['nuvemshop_product_id']) && !empty($mapping['nuvemshop_variant_id'])) {
                $nuvemProductId = $mapping['nuvemshop_product_id'];
                $nuvemVariantId = $mapping['nuvemshop_variant_id'];
            } else {
                // 2. Procura na Nuvemshop por SKU
                $searchResult = $this->nuvemshop->findProductVariantBySku($sku);

                // 3. Se não achou por SKU e tiver código de barras, tenta por código de barras
                if (!$searchResult && $barcode !== '') {
                    $searchResult = $this->nuvemshop->findProductVariantByBarcode($barcode);
                }

                if ($searchResult) {
                    $nuvemProductId = $searchResult['product']['id'];
                    $nuvemVariantId = $searchResult['variant']['id'];
                }
            }

            if ($nuvemProductId && $nuvemVariantId) {
                // Produto existe na Nuvemshop -> Atualiza Estoque
                $updateRes = $this->nuvemshop->updateVariantStock($nuvemProductId, $nuvemVariantId, (int)$estoque);
                
                if ($updateRes['success']) {
                    $status = 'synced';
                    $msg = "Estoque sincronizado na Nuvemshop com sucesso: {$estoque} un.";
                    Database::logSync('egestor', 'stock_updated', $codigo, "Produto '{$nome}' (SKU {$sku}) atualizado para {$estoque} un na Nuvemshop", 'success');
                } else {
                    $status = 'error';
                    $msg = "Erro ao atualizar estoque na Nuvemshop: " . ($updateRes['error'] ?? 'desconhecido');
                    Database::logSync('egestor', 'stock_update_failed', $codigo, $msg, 'error');
                }

                $this->saveMapping([
                    'egestor_id' => $codigo,
                    'nuvemshop_product_id' => $nuvemProductId,
                    'nuvemshop_variant_id' => $nuvemVariantId,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'name' => $nome,
                    'stock_egestor' => $estoque,
                    'stock_nuvemshop' => $estoque,
                    'price' => $precoVenda,
                    'status' => $status,
                    'last_sync_direction' => 'egestor_to_nuvem'
                ]);

                return ['success' => $updateRes['success'], 'message' => $msg, 'nuvemshop_product_id' => $nuvemProductId];
            } else {
                // Se a regra de enviar apenas com estoque estiver ativa e o estoque for <= 0, não cria na Nuvemshop
                if ($this->shouldSyncOnlyWithStock() && $estoque <= 0) {
                    $msg = "Produto '{$nome}' (ID {$codigo}) não enviado para a Nuvemshop pois possui estoque zerado/negativo ({$estoque} un) e a regra de 'Apenas produtos com estoque' está ativada.";
                    Logger::info($msg);
                    Database::logSync('egestor', 'skipped_zero_stock', $codigo, $msg, 'info');
                    return ['success' => true, 'message' => $msg, 'skipped' => true];
                }

                // Produto NÃO encontrado na Nuvemshop -> Cria automaticamente!
                Logger::info("Produto do eGestor ({$codigo} - {$nome}) não encontrado na Nuvemshop. Criando novo produto...");
                $createRes = $this->nuvemshop->createProduct([
                    'name' => $nome,
                    'description' => "Produto sincronizado automaticamente via PDV eGestor. SKU: {$sku}",
                    'price' => $precoVenda,
                    'stock' => (int)$estoque,
                    'sku' => $sku,
                    'barcode' => $barcode
                ]);

                if ($createRes['success'] && !empty($createRes['data']['id'])) {
                    $newProd = $createRes['data'];
                    $newVarId = $newProd['variants'][0]['id'] ?? null;

                    $this->saveMapping([
                        'egestor_id' => $codigo,
                        'nuvemshop_product_id' => $newProd['id'],
                        'nuvemshop_variant_id' => $newVarId,
                        'sku' => $sku,
                        'barcode' => $barcode,
                        'name' => $nome,
                        'stock_egestor' => $estoque,
                        'stock_nuvemshop' => $estoque,
                        'price' => $precoVenda,
                        'status' => 'synced',
                        'last_sync_direction' => 'egestor_to_nuvem'
                    ]);

                    $msg = "Produto criado na Nuvemshop com sucesso! ID: {$newProd['id']}, Estoque: {$estoque}";
                    Database::logSync('egestor', 'product_created_in_nuvem', $codigo, $msg, 'success');
                    return ['success' => true, 'message' => $msg, 'nuvemshop_product_id' => $newProd['id']];
                } else {
                    $err = "Erro ao criar produto na Nuvemshop: " . ($createRes['error'] ?? 'desconhecido');
                    Database::logSync('egestor', 'product_create_failed', $codigo, $err, 'error');
                    return ['success' => false, 'error' => $err];
                }
            }
        } catch (\Exception $e) {
            Logger::error("Exceção ao sincronizar produto {$codigo}: " . $e->getMessage());
            Database::logSync('egestor', 'sync_exception', $codigo, $e->getMessage(), 'error');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Sincroniza venda do eGestor (quando uma venda é feita no balcão/PDV)
     */
    public function syncSaleFromEGestor(string $codigoVenda): array {
        Logger::info("Venda realizada no PDV eGestor [Venda ID: {$codigoVenda}]. Sincronizando estoques relacionados...");
        Database::logSync('egestor', 'sale_completed', $codigoVenda, "Venda no PDV concluída. eGestor atualizará o estoque dos itens vendidos via webhook de produtos.", 'success');
        return ['success' => true, 'message' => 'Venda registrada com sucesso'];
    }

    /**
     * Processa webhook recebido da Nuvemshop (E-commerce)
     * Quando ocorre uma venda na Nuvemshop -> Dá baixa no estoque do eGestor (PDV)!
     */
    public function handleNuvemshopWebhook(string $event, array $payload): array {
        Logger::info("Webhook Nuvemshop recebido [Evento: {$event}]", $payload);
        $db = Database::getConnection();

        // 1. Tratamento de Vendas / Pedidos
        if (str_starts_with($event, 'order/')) {
            $orderId = trim((string)($payload['id'] ?? 'desconhecido'));

            // Idempotência: Se já processamos a baixa deste pedido, não baixa duas vezes (evita duplicar com order/created e order/paid)
            if ($event === 'order/created' || $event === 'order/paid') {
                $stmtCheck = $db->prepare("SELECT id FROM sync_logs WHERE source = 'nuvemshop' AND action = 'order_stock_deducted' AND entity_id = ? LIMIT 1");
                $stmtCheck->execute([$orderId]);
                if ($stmtCheck->fetchColumn()) {
                    Logger::info("Pedido Nuvemshop #{$orderId} já teve estoque baixado anteriormente. Ignorando evento repetido ({$event}).");
                    return ['success' => true, 'message' => "Estoque do pedido #{$orderId} já havia sido baixado."];
                }
            }

            // Se for cancelamento de pedido, devolve o estoque ao eGestor
            $isCancelled = ($event === 'order/cancelled' || ($payload['status'] ?? '') === 'cancelled');

            $products = $payload['products'] ?? [];
            if (empty($products)) {
                return ['success' => true, 'message' => 'Nenhum produto listado no pedido'];
            }

            $syncedItems = 0;

            foreach ($products as $item) {
                $sku = trim((string)($item['sku'] ?? ''));
                $barcode = trim((string)($item['barcode'] ?? ''));
                $qty = (float)($item['quantity'] ?? 1);
                $nuvemProdId = $item['product_id'] ?? null;
                $nuvemVarId = $item['variant_id'] ?? null;
                $prodName = $item['name'] ?? 'Produto';

                $egestorId = null;

                // A) Busca primeiro na tabela local de mapeamentos
                $stmt = $db->prepare("SELECT * FROM product_mappings WHERE nuvemshop_variant_id = ? OR (sku != '' AND sku = ?) LIMIT 1");
                $stmt->execute([$nuvemVarId, $sku]);
                $mapping = $stmt->fetch();

                if ($mapping && !empty($mapping['egestor_id'])) {
                    $egestorId = $mapping['egestor_id'];
                } elseif ($sku !== '') {
                    // B) Se não encontrou no cache local, pesquisa direto na API do eGestor pelo SKU
                    Logger::info("Buscando produto na API do eGestor pelo SKU: '{$sku}'...");
                    $egestorProd = $this->egestor->findProductBySku($sku);
                    if ($egestorProd && !empty($egestorProd['codigo'])) {
                        $egestorId = (string)$egestorProd['codigo'];
                    }
                }

                if ($egestorId) {
                    // Consulta o estoque atual no eGestor
                    $egestorProdRes = $this->egestor->getProduct($egestorId);
                    if ($egestorProdRes['success']) {
                        $currentStock = (float)($egestorProdRes['data']['estoque'] ?? 0);
                        
                        if ($isCancelled) {
                            $newStock = $currentStock + $qty;
                            $actionName = 'order_stock_restored';
                            $actionMsg = "Pedido Nuvemshop #{$orderId} CANCELADO: Estoque de {$prodName} (SKU {$sku}) devolvido de {$currentStock} para {$newStock} no eGestor";
                        } else {
                            $newStock = max(0, $currentStock - $qty);
                            $actionName = 'order_stock_deducted';
                            $actionMsg = "Venda Nuvemshop #{$orderId}: Estoque de {$prodName} (SKU {$sku}) baixado de {$currentStock} para {$newStock} no eGestor";
                        }

                        // Atualiza o estoque no eGestor
                        $updateRes = $this->egestor->updateProductStock($egestorId, $newStock);
                        if ($updateRes['success']) {
                            Logger::info($actionMsg);
                            Database::logSync('nuvemshop', $actionName, (string)$orderId, $actionMsg, 'success');

                            // Atualiza mapeamento no banco local
                            $this->saveMapping([
                                'egestor_id' => $egestorId,
                                'nuvemshop_product_id' => $nuvemProdId,
                                'nuvemshop_variant_id' => $nuvemVarId,
                                'sku' => $sku,
                                'barcode' => $barcode,
                                'name' => $prodName,
                                'stock_egestor' => $newStock,
                                'stock_nuvemshop' => $newStock,
                                'status' => 'synced',
                                'last_sync_direction' => 'nuvem_to_egestor'
                            ]);

                            $syncedItems++;
                        } else {
                            $errMsg = "Falha ao alterar estoque no eGestor: " . ($updateRes['error'] ?? 'Erro desconhecido');
                            Logger::error($errMsg);
                            Database::logSync('nuvemshop', 'stock_deduct_failed', (string)$orderId, $errMsg, 'error');
                        }
                    }
                } else {
                    $warnMsg = "Venda Nuvemshop #{$orderId}: Item '{$prodName}' (SKU: '{$sku}') não foi encontrado no eGestor.";
                    Logger::warning($warnMsg);
                    Database::logSync('nuvemshop', 'unmapped_product_sale', (string)$orderId, $warnMsg, 'warning');
                }
            }

            return ['success' => true, 'synced_items' => $syncedItems, 'order_id' => $orderId];
        }

        // 2. Tratamento de alteração manual de produto na Nuvemshop
        if ($event === 'product/updated' && !empty($payload['variants'])) {
            foreach ($payload['variants'] as $v) {
                $sku = trim((string)($v['sku'] ?? ''));
                $varId = $v['id'] ?? null;
                $stock = (float)($v['stock'] ?? 0);

                if ($sku !== '') {
                    $egestorProd = $this->egestor->findProductBySku($sku);
                    if ($egestorProd && !empty($egestorProd['codigo'])) {
                        $this->egestor->updateProductStock($egestorProd['codigo'], $stock);
                        Database::logSync('nuvemshop', 'product_stock_synced', (string)$egestorProd['codigo'], "Estoque do produto SKU {$sku} alinhado com a Nuvemshop: {$stock} un.", 'success');
                    }
                }
            }
            return ['success' => true, 'message' => 'Estoque do produto alinhado'];
        }

        return ['success' => true, 'message' => "Evento Nuvemshop '{$event}' recebido com sucesso."];
    }

    /**
     * Sincronização completa de produtos da Nuvemshop para a base de mapeamento local
     */
    public function importProductsFromNuvemshop(): array {
        Logger::info('Iniciando importação de catálogo da Nuvemshop...');
        $page = 1;
        $totalImported = 0;

        do {
            $res = $this->nuvemshop->getProducts($page, 50);
            if (!$res['success'] || empty($res['data'])) {
                break;
            }

            $products = $res['data'];
            foreach ($products as $prod) {
                $prodId = $prod['id'];
                $nome = $prod['name']['pt'] ?? 'Sem Nome';

                if (!empty($prod['variants'])) {
                    foreach ($prod['variants'] as $var) {
                        $varId = $var['id'];
                        $sku = trim((string)($var['sku'] ?? ''));
                        $barcode = trim((string)($var['barcode'] ?? ''));
                        $stock = (float)($var['stock'] ?? 0);
                        $price = (float)($var['price'] ?? 0);

                        $this->saveMapping([
                            'nuvemshop_product_id' => $prodId,
                            'nuvemshop_variant_id' => $varId,
                            'sku' => $sku,
                            'barcode' => $barcode,
                            'name' => $nome,
                            'stock_nuvemshop' => $stock,
                            'price' => $price,
                            'status' => 'synced',
                            'last_sync_direction' => 'nuvem_import'
                        ]);
                        $totalImported++;
                    }
                }
            }

            $page++;
        } while (count($products) >= 50);

        Database::logSync('manual', 'nuvemshop_import', 'all', "Catálogo Nuvemshop importado com sucesso: {$totalImported} variantes mapeadas.", 'success');
        return ['success' => true, 'total' => $totalImported];
    }

    /**
     * Salva ou atualiza mapeamento na tabela SQLite
     */
    private function saveMapping(array $data): void {
        $db = Database::getConnection();

        // Verifica se já existe por egestor_id, SKU ou variant_id
        $egestorId = $data['egestor_id'] ?? '';
        $sku = $data['sku'] ?? '';
        $varId = $data['nuvemshop_variant_id'] ?? '';

        $sqlCheck = "SELECT id FROM product_mappings WHERE (egestor_id != '' AND egestor_id = :egestor_id) OR (sku != '' AND sku = :sku) OR (nuvemshop_variant_id != '' AND nuvemshop_variant_id = :var_id) LIMIT 1";
        $stmtCheck = $db->prepare($sqlCheck);
        $stmtCheck->execute([
            ':egestor_id' => $egestorId,
            ':sku' => $sku,
            ':var_id' => $varId
        ]);
        $existingId = $stmtCheck->fetchColumn();

        if ($existingId) {
            $fields = [];
            $params = [':id' => $existingId];
            foreach ($data as $col => $val) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $val;
            }
            $fields[] = "updated_at = CURRENT_TIMESTAMP";
            $fields[] = "last_sync_at = CURRENT_TIMESTAMP";

            $updateSql = "UPDATE product_mappings SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $db->prepare($updateSql);
            $stmt->execute($params);
        } else {
            $cols = array_keys($data);
            $cols[] = 'last_sync_at';
            $placeholders = array_map(fn($c) => ":{$c}", array_keys($data));
            $placeholders[] = 'CURRENT_TIMESTAMP';

            $insertSql = "INSERT INTO product_mappings (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $db->prepare($insertSql);
            $stmt->execute($data);
        }
    }

    /**
     * Processa UMA página de produtos do eGestor e envia para a Nuvemshop.
     * Permite execução em lotes via AJAX com feedback e progresso em tempo real no painel.
     */
    public function exportPageFromEGestorToNuvemshop(int $page = 1, ?bool $onlyWithStock = null): array {
        if ($onlyWithStock === null) {
            $onlyWithStock = $this->shouldSyncOnlyWithStock();
        }
        Logger::info("Buscando página {$page} de produtos no eGestor (Apenas com estoque: " . ($onlyWithStock ? 'Sim' : 'Não') . ")...");
        $res = $this->egestor->getProducts($page);

        if (!$res['success'] || empty($res['data'])) {
            $err = $res['error'] ?? 'Falha ao buscar produtos no eGestor. Verifique o Personal Token.';
            return ['success' => false, 'error' => $err];
        }

        $products = $res['data']['data'] ?? [];
        $lastPage = (int)($res['data']['last_page'] ?? $page);
        $totalItems = (int)($res['data']['total'] ?? 0);

        $db = Database::getConnection();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $itemsLog = [];

        foreach ($products as $prod) {
            $codigo = trim((string)($prod['codigo'] ?? ''));
            if ($codigo === '') continue;

            $nome = trim($prod['descricao'] ?? 'Produto ' . $codigo);
            $rawSku = trim((string)($prod['codigoProprio'] ?? ''));
            $sku = $rawSku !== '' ? $rawSku : $codigo;

            // Filtra e normaliza código de barras (descarta SEM GTIN)
            $rawBarcode = trim((string)($prod['refEanGtin'] ?? ''));
            $upperBarcode = strtoupper($rawBarcode);
            $barcode = ($rawBarcode === '' || $upperBarcode === 'SEM GTIN' || $upperBarcode === 'SEM_GTIN' || $rawBarcode === '0') ? '' : $rawBarcode;

            // Nuvemshop não aceita estoque negativo
            $estoque = max(0, (float)($prod['estoque'] ?? 0));
            $precoVenda = (float)($prod['precoVenda'] ?? 0);

            // Imagens do eGestor se houver
            $images = [];
            if (!empty($prod['listaImagens']) && is_array($prod['listaImagens'])) {
                foreach ($prod['listaImagens'] as $imgItem) {
                    $link = $imgItem['foto']['link'] ?? $imgItem['thumb']['link'] ?? null;
                    if ($link) $images[] = $link;
                }
            }

            // 1. Tenta achar na Nuvemshop (via banco local ou busca por SKU/Barcode real)
            $stmt = $db->prepare("SELECT * FROM product_mappings WHERE (egestor_id != '' AND egestor_id = ?) OR (sku != '' AND sku = ?) LIMIT 1");
            $stmt->execute([$codigo, $sku]);
            $mapping = $stmt->fetch();

            $nuvemProductId = null;
            $nuvemVariantId = null;

            if ($mapping && !empty($mapping['nuvemshop_product_id']) && !empty($mapping['nuvemshop_variant_id'])) {
                $nuvemProductId = $mapping['nuvemshop_product_id'];
                $nuvemVariantId = $mapping['nuvemshop_variant_id'];
            } else {
                $search = $this->nuvemshop->findProductVariantBySku($sku);
                if (!$search && $barcode !== '') {
                    $search = $this->nuvemshop->findProductVariantByBarcode($barcode);
                }
                if ($search) {
                    $nuvemProductId = $search['product']['id'];
                    $nuvemVariantId = $search['variant']['id'];
                }
            }

            if ($nuvemProductId && $nuvemVariantId) {
                // Produto JÁ existe na Nuvemshop -> Atualiza estoque e preço
                $upStock = $this->nuvemshop->updateVariantStock($nuvemProductId, $nuvemVariantId, (int)$estoque);
                if ($precoVenda > 0) {
                    $this->nuvemshop->updateVariantPrice($nuvemProductId, $nuvemVariantId, $precoVenda);
                }

                $this->saveMapping([
                    'egestor_id' => $codigo,
                    'nuvemshop_product_id' => $nuvemProductId,
                    'nuvemshop_variant_id' => $nuvemVariantId,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'name' => $nome,
                    'stock_egestor' => $estoque,
                    'stock_nuvemshop' => $estoque,
                    'price' => $precoVenda,
                    'status' => $upStock['success'] ? 'synced' : 'error',
                    'last_sync_direction' => 'egestor_mass_sync'
                ]);

                if ($upStock['success']) {
                    $updated++;
                    $itemsLog[] = "Atualizado: {$nome} (SKU {$sku}) - Estoque: {$estoque} un.";
                } else {
                    $errors++;
                    $itemsLog[] = "Erro ao atualizar: {$nome} (SKU {$sku})";
                }
            } else {
                // Se a opção 'Apenas com estoque' estiver marcada e o produto tiver estoque 0, ignora criação
                if ($onlyWithStock && $estoque <= 0) {
                    $skipped++;
                    $itemsLog[] = "Ignorado (sem estoque): {$nome} (SKU {$sku})";
                    continue;
                }

                // Produto NÃO existe na Nuvemshop -> Cria do zero
                $createRes = $this->nuvemshop->createProduct([
                    'name' => $nome,
                    'description' => "Importado do eGestor PDV. Código: {$codigo}",
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'price' => $precoVenda,
                    'stock' => (int)$estoque,
                    'images' => $images
                ]);

                if ($createRes['success'] && !empty($createRes['data']['id'])) {
                    $newProd = $createRes['data'];
                    $newVarId = $newProd['variants'][0]['id'] ?? null;

                    $this->saveMapping([
                        'egestor_id' => $codigo,
                        'nuvemshop_product_id' => $newProd['id'],
                        'nuvemshop_variant_id' => $newVarId,
                        'sku' => $sku,
                        'barcode' => $barcode,
                        'name' => $nome,
                        'stock_egestor' => $estoque,
                        'stock_nuvemshop' => $estoque,
                        'price' => $precoVenda,
                        'status' => 'synced',
                        'last_sync_direction' => 'egestor_mass_import'
                    ]);
                    $created++;
                    $itemsLog[] = "Criado na Nuvemshop: {$nome} (SKU {$sku}) - Estoque: {$estoque} un.";
                } else {
                    $errors++;
                    $errDetail = $createRes['error'] ?? 'Erro desconhecido';
                    $itemsLog[] = "Erro ao criar: {$nome} (SKU {$sku}) - {$errDetail}";
                    Logger::warning("Erro ao criar produto {$nome} (ID {$codigo}) na Nuvemshop: {$errDetail}");
                }
            }

            // Pausa de 150ms entre produtos
            usleep(150000);
        }

        return [
            'success' => true,
            'page' => $page,
            'last_page' => $lastPage,
            'total_items' => $totalItems,
            'processed' => count($products),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'logs' => $itemsLog
        ];
    }

    /**
     * Importa/Exporta TODOS os produtos já cadastrados no eGestor para a Nuvemshop em laço sequencial.
     */
    public function exportAllFromEGestorToNuvemshop(?callable $progressCallback = null, ?bool $onlyWithStock = null): array {
        if ($onlyWithStock === null) {
            $onlyWithStock = $this->shouldSyncOnlyWithStock();
        }
        Logger::info('Iniciando importação completa de todos os produtos do eGestor para a Nuvemshop...');

        $page = 1;
        $totalProcessed = 0;
        $totalCreated = 0;
        $totalUpdated = 0;
        $totalErrors = 0;
        $totalSkipped = 0;

        try {
            do {
                $pageRes = $this->exportPageFromEGestorToNuvemshop($page, $onlyWithStock);
                if (!$pageRes['success']) {
                    return $pageRes;
                }

                $totalProcessed += $pageRes['processed'];
                $totalCreated += $pageRes['created'];
                $totalUpdated += $pageRes['updated'];
                $totalErrors += $pageRes['errors'];
                $totalSkipped += $pageRes['skipped'] ?? 0;
                $lastPage = $pageRes['last_page'];

                if ($progressCallback) {
                    $progressCallback($page, $lastPage, $totalProcessed, $totalCreated, $totalUpdated, $totalErrors, $totalSkipped);
                }

                $page++;
            } while ($page <= $lastPage);

            $summary = "Importação concluída! Total processados: {$totalProcessed} | Novos na Nuvemshop: {$totalCreated} | Atualizados: {$totalUpdated} | Ignorados (sem estoque): {$totalSkipped} | Falhas: {$totalErrors}";
            Logger::info($summary);
            Database::logSync('manual', 'egestor_mass_import', 'all', $summary, $totalErrors > 0 ? 'warning' : 'success');

            return [
                'success' => true,
                'message' => $summary,
                'total_processed' => $totalProcessed,
                'total_created' => $totalCreated,
                'total_updated' => $totalUpdated,
                'total_skipped' => $totalSkipped,
                'total_errors' => $totalErrors
            ];
        } catch (\Throwable $e) {
            $err = "Erro ao importar produtos do eGestor: " . $e->getMessage();
            Logger::error($err);
            Database::logSync('manual', 'egestor_mass_import_failed', 'all', $err, 'error');
            return ['success' => false, 'error' => $err];
        }
    }

    /**
     * Retorna se a regra padrão de sincronizar apenas produtos com estoque > 0 está ativa
     */
    public function shouldSyncOnlyWithStock(): bool {
        $dbVal = Database::getSetting('sync_only_with_stock');
        if ($dbVal !== null) {
            return filter_var($dbVal, FILTER_VALIDATE_BOOLEAN);
        }
        $envVal = getenv('SYNC_ONLY_WITH_STOCK');
        return ($envVal === false) ? true : filter_var($envVal, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Define a regra padrão de sincronização
     */
    public function setSyncOnlyWithStock(bool $val): void {
        Database::setSetting('sync_only_with_stock', $val ? '1' : '0');
    }
}
