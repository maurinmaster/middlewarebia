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
    public function syncProductFromEGestor(string $codigo, string $action = 'updated', bool $forceCreate = false): array {
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
            $rawSku      = trim((string)($prod['codigoProprio'] ?? ''));
            // Caso não tenha SKU (codigoProprio) no eGestor, usa o código gerado pelo eGestor como SKU na Nuvemshop
            $sku         = $rawSku !== '' ? $rawSku : (string)$codigo;
            $rawBarcode  = trim($prod['refEanGtin'] ?? '');
            $upperBarcode = strtoupper($rawBarcode);
            $barcode     = ($rawBarcode === '' || $upperBarcode === 'SEM GTIN' || $upperBarcode === 'SEM_GTIN' || $rawBarcode === '0') ? '' : $rawBarcode;
            $estoque     = max(0, (float)($prod['estoque'] ?? 0));
            $precoVenda  = (float)($prod['precoVenda'] ?? 0);

            // Imagens do produto no eGestor se houver
            $images = [];
            if (!empty($prod['listaImagens']) && is_array($prod['listaImagens'])) {
                foreach ($prod['listaImagens'] as $imgItem) {
                    $link = $imgItem['foto']['link'] ?? $imgItem['thumb']['link'] ?? null;
                    if ($link) $images[] = $link;
                }
            }

            $db = Database::getConnection();

            // 1. Tenta achar mapeamento existente no banco local
            $stmt = $db->prepare("SELECT * FROM product_mappings WHERE (egestor_id = ? OR (sku != '' AND sku = ?)) AND nuvemshop_product_id IS NOT NULL AND nuvemshop_product_id != '' AND status != 'removed_from_nuvem' LIMIT 1");
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
                // Produto existe na Nuvemshop -> Atualiza Estoque e Preço
                $updateRes = $this->nuvemshop->updateVariantStock($nuvemProductId, $nuvemVariantId, (int)$estoque);
                if ($precoVenda > 0) {
                    $this->nuvemshop->updateVariantPrice($nuvemProductId, $nuvemVariantId, $precoVenda);
                }
                // Se a variante na Nuvemshop não tiver SKU, preenche com o SKU/código do eGestor
                if (!empty($searchResult['variant']) && trim((string)($searchResult['variant']['sku'] ?? '')) === '') {
                    $this->nuvemshop->updateVariant($nuvemProductId, $nuvemVariantId, ['sku' => $sku]);
                }
                
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
                // Se NÃO foi encontrado mapeamento na Nuvemshop:
                // Se for webhook automático do eGestor ($forceCreate === false), NUNCA cria produto avulso automaticamente!
                // O lojista cadastra produtos pela tela de cadastro (com grade) ou pelo botão manual "Enviar para Nuvem" no Vizor.
                if (!$forceCreate) {
                    $msg = "Produto do eGestor [ID {$codigo} - '{$nome}'] não está mapeado na Nuvemshop. Ignorando criação avulsa automática via webhook.";
                    Logger::info($msg);
                    Database::logSync('egestor', 'unmapped_product_skipped', $codigo, $msg, 'info');
                    return ['success' => true, 'message' => $msg, 'skipped' => true];
                }

                // Se for envio manual forçado pelo lojista ($forceCreate === true):
                if ($this->shouldSyncOnlyWithStock() && $estoque <= 0) {
                    $msg = "Produto '{$nome}' (ID {$codigo}) não enviado para a Nuvemshop pois possui estoque zerado/negativo ({$estoque} un) e a regra de 'Apenas produtos com estoque' está ativada.";
                    Logger::info($msg);
                    Database::logSync('egestor', 'skipped_zero_stock', $codigo, $msg, 'info');
                    return ['success' => true, 'message' => $msg, 'skipped' => true];
                }

                // Cria produto avulso na Nuvemshop apenas sob comando manual explícito do usuário
                Logger::info("Criando produto avulso na Nuvemshop sob comando manual do usuário: [{$codigo} - {$nome}]...");
                $createRes = $this->nuvemshop->createProduct([
                    'name' => $nome,
                    'description' => "Produto importado do eGestor PDV. Código: {$codigo}",
                    'price' => $precoVenda,
                    'stock' => (int)$estoque,
                    'sku' => $sku,
                    'barcode' => $barcode,
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
            if ($orderId === 'desconhecido' || $orderId === '') {
                Logger::warning("Webhook Nuvemshop recebido sem ID de pedido válido.", $payload);
                return ['success' => false, 'error' => 'ID de pedido ausente'];
            }

            // Webhooks da Nuvemshop enviam payload enxuto (thin payload: store_id, event, id).
            // Se o payload não tiver os produtos, busca o pedido completo via API REST da Nuvemshop:
            if (empty($payload['products'])) {
                Logger::info("Payload thin recebido para pedido #{$orderId}. Buscando dados completos na API Nuvemshop...");
                $orderRes = $this->nuvemshop->getOrder($orderId);
                if ($orderRes['success'] && !empty($orderRes['data'])) {
                    $payload = $orderRes['data'];
                } else {
                    $err = $orderRes['error'] ?? 'Falha ao buscar dados do pedido na Nuvemshop';
                    Logger::error("Não foi possível carregar pedido #{$orderId}: {$err}");
                    return ['success' => false, 'error' => $err];
                }
            }

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

                // A) Busca na tabela local de mapeamentos com prioridade estrita:
                // 1º Por ID da Variante (garante que produtos com grade/variações unificadas encontrem o eGestor ID exato daquela cor/tamanho!)
                $mapping = null;
                if (!empty($nuvemVarId)) {
                    $stmt = $db->prepare("SELECT * FROM product_mappings WHERE nuvemshop_variant_id = ? LIMIT 1");
                    $stmt->execute([(string)$nuvemVarId]);
                    $mapping = $stmt->fetch();
                }

                // 2º Por SKU
                if (!$mapping && $sku !== '') {
                    $stmt = $db->prepare("SELECT * FROM product_mappings WHERE sku != '' AND sku = ? LIMIT 1");
                    $stmt->execute([$sku]);
                    $mapping = $stmt->fetch();
                }

                // 3º Por Código de Barras
                if (!$mapping && $barcode !== '') {
                    $stmt = $db->prepare("SELECT * FROM product_mappings WHERE barcode != '' AND barcode = ? LIMIT 1");
                    $stmt->execute([$barcode]);
                    $mapping = $stmt->fetch();
                }

                // 4º Por ID do Produto Pai (apenas fallback para produtos de variante única)
                if (!$mapping && !empty($nuvemProdId)) {
                    $stmt = $db->prepare("SELECT * FROM product_mappings WHERE nuvemshop_product_id = ? LIMIT 1");
                    $stmt->execute([(string)$nuvemProdId]);
                    $mapping = $stmt->fetch();
                }

                if ($mapping && !empty($mapping['egestor_id'])) {
                    $egestorId = $mapping['egestor_id'];
                } elseif ($sku !== '') {
                    // B) Se não encontrou no cache local, pesquisa direto na API do eGestor pelo SKU
                    Logger::info("Buscando produto na API do eGestor pelo SKU: '{$sku}'...");
                    $egestorProd = $this->egestor->findProductBySku($sku);
                    if ($egestorProd && !empty($egestorProd['codigo'])) {
                        $egestorId = (string)$egestorProd['codigo'];
                    }
                } elseif ($barcode !== '') {
                    Logger::info("Buscando produto na API do eGestor pelo Código de Barras: '{$barcode}'...");
                    $egestorProd = $this->egestor->findProductByBarcode($barcode);
                    if ($egestorProd && !empty($egestorProd['codigo'])) {
                        $egestorId = (string)$egestorProd['codigo'];
                    }
                }

                // C) Fallback: se não achou por SKU nem código de barras, busca por nome/descrição no eGestor
                if (!$egestorId && !empty($prodName)) {
                    Logger::info("Buscando produto no eGestor pelo nome: '{$prodName}'...");
                    $searchRes = $this->egestor->getProducts(1, ['filtro' => $prodName]);
                    if ($searchRes['success'] && !empty($searchRes['data']['data'])) {
                        foreach ($searchRes['data']['data'] as $cand) {
                            if (mb_strtolower(trim($cand['descricao'])) === mb_strtolower(trim($prodName))) {
                                $egestorId = (string)$cand['codigo'];
                                Logger::info("Produto encontrado no eGestor por nome! ID: {$egestorId}");
                                break;
                            }
                        }
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

                        // Venda ou Cancelamento na Nuvemshop: atualiza o estoque no eGestor (PDV)
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

        // 2. Tratamento de alteração de produto na Nuvemshop (NUNCA altera o eGestor)
        if ($event === 'product/updated') {
            $prodId = $payload['id'] ?? null;
            if ($prodId && empty($payload['variants'])) {
                Logger::info("Payload thin recebido para produto #{$prodId}. Buscando variantes na API Nuvemshop...");
                $prodRes = $this->nuvemshop->getProduct($prodId);
                if ($prodRes['success'] && !empty($prodRes['data']['variants'])) {
                    $payload['variants'] = $prodRes['data']['variants'];
                }
            }

            if (!empty($payload['variants'])) {
                foreach ($payload['variants'] as $v) {
                    $sku = trim((string)($v['sku'] ?? ''));
                    $stock = (float)($v['stock'] ?? 0);

                    if ($sku !== '') {
                        $db = Database::getConnection();
                        $stmt = $db->prepare("UPDATE product_mappings SET stock_nuvemshop = ?, updated_at = CURRENT_TIMESTAMP WHERE sku = ?");
                        $stmt->execute([$stock, $sku]);
                    }
                }
            }
            $safeMsg = "Evento product/updated recebido da Nuvemshop. Cadastro e estoque do eGestor preservados intactos.";
            Logger::info($safeMsg);
            return ['success' => true, 'message' => $safeMsg];
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
                // Se a variante na Nuvemshop não tiver SKU, preenche com o SKU/código do eGestor
                if (!empty($search['variant']) && trim((string)($search['variant']['sku'] ?? '')) === '') {
                    $this->nuvemshop->updateVariant($nuvemProductId, $nuvemVariantId, ['sku' => $sku]);
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

    /**
     * Retorna se a Nuvemshop tem autorização para modificar produtos ou estoque no eGestor
     * Por padrão: FALSE (nunca modifica produtos e estoques do eGestor)
     */
    public function canNuvemshopModifyEGestor(): bool {
        $dbVal = Database::getSetting('allow_nuvem_modify_egestor');
        if ($dbVal !== null) {
            return filter_var($dbVal, FILTER_VALIDATE_BOOLEAN);
        }
        $envVal = getenv('ALLOW_NUVEM_MODIFY_EGESTOR');
        return ($envVal === false) ? false : filter_var($envVal, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Exclui um produto da Nuvemshop (retirada manual).
     * REGRA ESTRITA: NUNCA altera ou remove o produto no eGestor!
     */
    public function removeProductFromNuvemshop(int|string $nuvemshopProductId): array {
        try {
            $db = Database::getConnection();

            // Busca mapeamento
            $stmt = $db->prepare("SELECT * FROM product_mappings WHERE nuvemshop_product_id = ? LIMIT 1");
            $stmt->execute([(string)$nuvemshopProductId]);
            $mapping = $stmt->fetch();

            $nome = $mapping['name'] ?? "Produto ID {$nuvemshopProductId}";
            $sku = $mapping['sku'] ?? '';

            // 1. Exclui da Nuvemshop via API
            $res = $this->nuvemshop->deleteProduct($nuvemshopProductId);

            if ($res['success'] || ($res['status'] ?? 0) === 404) {
                // 2. Atualiza mapeamento no banco local desvinculando da Nuvemshop
                $stmtUp = $db->prepare("UPDATE product_mappings SET nuvemshop_product_id = NULL, nuvemshop_variant_id = NULL, status = 'removed_from_nuvem', stock_nuvemshop = 0, last_sync_direction = 'manual_remove', updated_at = CURRENT_TIMESTAMP WHERE nuvemshop_product_id = ?");
                $stmtUp->execute([(string)$nuvemshopProductId]);

                $msg = "Produto '{$nome}' (Nuvemshop ID #{$nuvemshopProductId}) foi retirado da Nuvemshop com sucesso! O cadastro no eGestor permaneceu 100% intacto.";
                Logger::info($msg);
                Database::logSync('manual', 'product_removed_from_nuvem', (string)$nuvemshopProductId, $msg, 'success');

                return ['success' => true, 'message' => $msg];
            } else {
                $err = $res['error'] ?? 'Falha ao remover produto da Nuvemshop';
                Logger::error("Erro ao remover produto {$nuvemshopProductId} da Nuvemshop: {$err}");
                return ['success' => false, 'error' => $err];
            }
        } catch (\Throwable $e) {
            $err = "Erro ao processar remoção: " . $e->getMessage();
            Logger::error($err);
            return ['success' => false, 'error' => $err];
        }
    }

    /**
     * Vizor: Compara catálogo do eGestor com a Nuvemshop.
     * Retorna produtos do eGestor indicando quais já estão na Nuvemshop e quais ainda não foram enviados.
     */
    public function getEGestorVsNuvemProducts(int $page = 1, string $filter = '', string|bool $statusFilter = 'missing', string $stockFilter = 'all'): array {
        try {
            $params = [];
            if ($filter !== '') {
                $params['filtro'] = $filter;
            }

            $res = $this->egestor->getProducts($page, $params);
            if (!$res['success'] || empty($res['data'])) {
                return [
                    'success' => false,
                    'error' => $res['error'] ?? 'Falha ao buscar produtos no eGestor. Verifique o Personal Token.'
                ];
            }

            $rawProducts = $res['data']['data'] ?? [];
            $totalEGestor = (int)($res['data']['total'] ?? 0);
            $lastPage = (int)($res['data']['last_page'] ?? 1);

            $db = Database::getConnection();

            // Normaliza statusFilter
            if (is_bool($statusFilter)) {
                $statusFilter = $statusFilter ? 'missing' : 'all';
            }

            // Indexa produtos retornados para checar existência no banco local de forma rápida
            $codigos = [];
            $skus = [];
            foreach ($rawProducts as $p) {
                if (!empty($p['codigo'])) {
                    $c = (string)$p['codigo'];
                    $codigos[] = $c;
                    $rawSku = trim((string)($p['codigoProprio'] ?? ''));
                    $skus[] = $rawSku !== '' ? $rawSku : $c;
                }
            }

            $mappedByEGestorId = [];
            $mappedBySku = [];

            if (!empty($codigos) || !empty($skus)) {
                $inCodigos = !empty($codigos) ? implode(',', array_fill(0, count($codigos), '?')) : 'NULL';
                $inSkus = !empty($skus) ? implode(',', array_fill(0, count($skus), '?')) : 'NULL';
                
                $paramsQuery = array_merge($codigos, $skus);
                $stmt = $db->prepare("SELECT egestor_id, nuvemshop_product_id, nuvemshop_variant_id, sku, barcode, status, stock_nuvemshop FROM product_mappings 
                    WHERE (egestor_id IN ({$inCodigos}) OR (sku != '' AND sku IN ({$inSkus})))
                    AND nuvemshop_product_id IS NOT NULL AND nuvemshop_product_id != '' AND status != 'removed_from_nuvem'");
                $stmt->execute($paramsQuery);
                $mappings = $stmt->fetchAll();

                foreach ($mappings as $m) {
                    if (!empty($m['egestor_id'])) {
                        $mappedByEGestorId[$m['egestor_id']] = $m;
                    }
                    if (!empty($m['sku'])) {
                        $mappedBySku[$m['sku']] = $m;
                    }
                }
            }

            $items = [];
            foreach ($rawProducts as $p) {
                $codigo = (string)($p['codigo'] ?? '');
                $rawSku = trim((string)($p['codigoProprio'] ?? ''));
                $sku = $rawSku !== '' ? $rawSku : $codigo;
                $nome = trim((string)($p['descricao'] ?? 'Produto ' . $codigo));
                $estoque = (float)($p['estoque'] ?? 0);
                $preco = (float)($p['precoVenda'] ?? 0);
                $rawBarcode = trim((string)($p['refEanGtin'] ?? ''));
                $upperBarcode = strtoupper($rawBarcode);
                $barcode = ($rawBarcode === '' || $upperBarcode === 'SEM GTIN' || $upperBarcode === 'SEM_GTIN' || $rawBarcode === '0') ? '' : $rawBarcode;

                $mapping = $mappedByEGestorId[$codigo] ?? ($sku !== '' ? ($mappedBySku[$sku] ?? null) : null);
                $inNuvem = !empty($mapping['nuvemshop_product_id']);

                // Filtro de Status na Nuvemshop
                if ($statusFilter === 'missing' && $inNuvem) {
                    continue; // Apenas os que NÃO estão na nuvem
                }
                if ($statusFilter === 'in_nuvem' && !$inNuvem) {
                    continue; // Apenas os que JÁ estão na nuvem
                }

                // Filtro de Estoque no eGestor
                if ($stockFilter === 'has_stock' && $estoque <= 0) {
                    continue; // Apenas com estoque > 0
                }
                if ($stockFilter === 'zero_stock' && $estoque > 0) {
                    continue; // Apenas estoque zerado
                }
                if ($stockFilter === 'low_stock' && ($estoque <= 0 || $estoque > 3)) {
                    continue; // Apenas estoque baixo (1 a 3)
                }

                $items[] = [
                    'codigo' => $codigo,
                    'sku' => $sku !== '' ? $sku : $codigo,
                    'nome' => $nome,
                    'barcode' => $barcode,
                    'estoque' => $estoque,
                    'preco' => $preco,
                    'in_nuvemshop' => $inNuvem,
                    'nuvemshop_product_id' => $mapping['nuvemshop_product_id'] ?? null,
                    'nuvemshop_variant_id' => $mapping['nuvemshop_variant_id'] ?? null,
                    'stock_nuvemshop' => $mapping['stock_nuvemshop'] ?? null
                ];
            }

            return [
                'success' => true,
                'page' => $page,
                'last_page' => $lastPage,
                'total_egestor' => $totalEGestor,
                'items_count' => count($items),
                'items' => $items
            ];
        } catch (\Throwable $e) {
            Logger::error('Erro ao consultar produtos eGestor vs Nuvem: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cadastro Completo de Produto no Middleware:
     * Salva o produto na Nuvemshop (com grade unificada de cor/modelo se houver variações)
     * e salva cada variação SEPARADAMENTE no eGestor (PDV), vinculando automaticamente
     * os estoques e códigos para que vendas futuras na Nuvemshop deem baixa no produto exato do eGestor.
     */
    public function createFullProduct(array $data): array {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'O nome do produto é obrigatório.'];
        }

        $price = (float)($data['price'] ?? 0);
        $costPrice = !empty($data['cost_price']) ? (float)$data['cost_price'] : 0.0;
        $description = trim((string)($data['description'] ?? ''));
        $hasVariants = !empty($data['has_variants']) && !empty($data['variants']);
        $images = !empty($data['images']) && is_array($data['images']) ? $data['images'] : [];

        // CASO 1: PRODUTO SIMPLES (Sem Variações)
        if (!$hasVariants) {
            $stock = (float)($data['stock'] ?? 0);
            $sku = trim((string)($data['sku'] ?? ''));
            $barcode = trim((string)($data['barcode'] ?? ''));

            // 1. Cria primeiro na Nuvemshop
            $nuvemPayload = [
                'name' => $name,
                'description' => $description,
                'price' => $price,
                'stock' => (int)$stock,
                'sku' => $sku,
                'barcode' => $barcode,
                'images' => $images
            ];

            Logger::info("Cadastrando produto simples na Nuvemshop: '{$name}'...");
            $nRes = $this->nuvemshop->createProduct($nuvemPayload);
            if (!$nRes['success'] || empty($nRes['data']['id'])) {
                $err = $nRes['error'] ?? 'Falha ao cadastrar produto na Nuvemshop';
                Logger::error("Erro no cadastro Nuvemshop: {$err}");
                return ['success' => false, 'error' => "Falha na Nuvemshop: {$err}"];
            }

            $nuvemshopProdId = (string)$nRes['data']['id'];
            $nuvemshopVarId = (string)($nRes['data']['variants'][0]['id'] ?? '');

            // 2. Cria no eGestor
            $eData = [
                'descricao' => $name,
                'precoVenda' => $price,
                'precoCusto' => $costPrice,
                'estoque' => $stock,
                'codigoProprio' => $sku,
                'refEanGtin' => $barcode,
                'anotacoesInternas' => "Nuvemshop #{$nuvemshopProdId}"
            ];

            Logger::info("Cadastrando produto simples no eGestor: '{$name}'...");
            $eRes = $this->egestor->createProduct($eData);
            if (!$eRes['success'] || empty($eRes['data']['codigo'])) {
                $err = $eRes['error'] ?? 'Falha ao cadastrar produto no eGestor';
                Logger::error("Erro no cadastro eGestor: {$err}");
                return ['success' => false, 'error' => "Criado na Nuvemshop (#{$nuvemshopProdId}), mas falhou no eGestor: {$err}"];
            }

            $egestorId = (string)$eRes['data']['codigo'];
            $finalSku = $sku !== '' ? $sku : $egestorId;

            // Se o SKU não estava preenchido, atualiza a variante na Nuvemshop com o código do eGestor
            if ($sku === '' && $nuvemshopVarId !== '') {
                $this->nuvemshop->updateVariant($nuvemshopProdId, $nuvemshopVarId, ['sku' => $finalSku]);
            }

            // 3. Salva Mapeamento Imediatamente
            $this->saveMapping([
                'egestor_id' => $egestorId,
                'nuvemshop_product_id' => $nuvemshopProdId,
                'nuvemshop_variant_id' => $nuvemshopVarId,
                'sku' => $finalSku,
                'barcode' => $barcode,
                'name' => $name,
                'stock_egestor' => $stock,
                'stock_nuvemshop' => $stock,
                'price' => $price,
                'status' => 'synced',
                'last_sync_direction' => 'full_create_simple'
            ]);

            Database::logSync('manual', 'full_create_simple', $egestorId, "Produto simples '{$name}' criado na Nuvemshop (#{$nuvemshopProdId}) e eGestor (#{$egestorId})", 'success');

            return [
                'success' => true,
                'message' => "Produto '{$name}' cadastrado com sucesso no eGestor e na Nuvemshop!",
                'nuvemshop_product_id' => $nuvemshopProdId,
                'egestor_id' => $egestorId
            ];
        }

        // CASO 2: PRODUTO COM VARIAÇÕES (GRADE: COR / MODELO / TAMANHO)
        $attributes = (array)($data['attributes'] ?? []);
        if (empty($attributes)) {
            $attributes = ['Cor', 'Tamanho'];
        }

        $variants = (array)$data['variants'];
        if (count($variants) < 1) {
            return ['success' => false, 'error' => 'Nenhuma variação informada para a grade.'];
        }

        Logger::info("Iniciando cadastro completo com grade para '{$name}' (" . count($variants) . " variações)...");

        // 1. Monta as variações para a Nuvemshop
        $nuvemVariants = [];
        foreach ($variants as $idx => $v) {
            $rawValues = (array)($v['values'] ?? []);
            $varPrice = isset($v['price']) && $v['price'] !== '' ? (float)$v['price'] : $price;
            $varStock = isset($v['stock']) && $v['stock'] !== '' ? (float)$v['stock'] : 0.0;
            $varSku = trim((string)($v['sku'] ?? ''));
            $varBarcode = trim((string)($v['barcode'] ?? ''));

            $nuvemValues = [];
            foreach ($rawValues as $val) {
                $nuvemValues[] = ['pt' => trim((string)$val)];
            }

            $nuvemVariants[] = [
                'price' => $varPrice,
                'stock' => (int)$varStock,
                'sku' => $varSku,
                'barcode' => $varBarcode,
                'values' => $nuvemValues
            ];
        }

        // 2. CRIA PRIMEIRO NA NUVEMSHOP O PRODUTO UNIFICADO COM A GRADE COMPLETA
        $nuvemPayload = [
            'name' => $name,
            'description' => $description,
            'attributes' => array_map(fn($a) => is_array($a) ? $a : ['pt' => trim((string)$a)], $attributes),
            'variants' => $nuvemVariants,
            'images' => $images
        ];

        Logger::info("Criando produto com grade unificada na Nuvemshop: '{$name}' com " . count($nuvemVariants) . " variações...");
        $nRes = $this->nuvemshop->createProduct($nuvemPayload);

        if (!$nRes['success'] || empty($nRes['data']['id'])) {
            $err = $nRes['error'] ?? 'Falha ao criar produto unificado na Nuvemshop';
            Logger::error("Erro na criação unificada na Nuvemshop: {$err}");
            return ['success' => false, 'error' => "Falha ao criar produto na Nuvemshop: {$err}"];
        }

        $createdNuvemProduct = $nRes['data'];
        $createdNuvemProdId = (string)$createdNuvemProduct['id'];
        $returnedVariants = $createdNuvemProduct['variants'] ?? [];

        // 3. CRIA CADA VARIAÇÃO SEPARADA NO EGESTOR E REGISTRA O MAPEAMENTO IMEDIATAMENTE
        $mappedCount = 0;
        foreach ($variants as $idx => $v) {
            $rawValues = (array)($v['values'] ?? []);
            $valuesText = implode(' - ', array_map('trim', $rawValues));
            $varName = $name . ($valuesText !== '' ? " - {$valuesText}" : " - Var " . ($idx + 1));
            $varPrice = isset($v['price']) && $v['price'] !== '' ? (float)$v['price'] : $price;
            $varCost = isset($v['cost_price']) && $v['cost_price'] !== '' ? (float)$v['cost_price'] : $costPrice;
            $varStock = isset($v['stock']) && $v['stock'] !== '' ? (float)$v['stock'] : 0.0;
            $varSku = trim((string)($v['sku'] ?? ''));
            $varBarcode = trim((string)($v['barcode'] ?? ''));

            // Localiza a variante criada na Nuvemshop correspondente
            $createdVariant = $returnedVariants[$idx] ?? null;
            $createdVarId = (string)($createdVariant['id'] ?? '');

            // Cria o produto individual no eGestor (PDV)
            $eData = [
                'descricao' => $varName,
                'precoVenda' => $varPrice,
                'precoCusto' => $varCost,
                'estoque' => $varStock,
                'codigoProprio' => $varSku,
                'refEanGtin' => $varBarcode,
                'anotacoesInternas' => "Grade: " . implode(' / ', $rawValues) . " (Nuvemshop #{$createdNuvemProdId})"
            ];

            $eRes = $this->egestor->createProduct($eData);
            if (!$eRes['success'] || empty($eRes['data']['codigo'])) {
                $err = $eRes['error'] ?? 'Falha ao criar item no eGestor';
                Logger::error("Erro ao cadastrar variação no eGestor [{$varName}]: {$err}");
                continue;
            }

            $createdEGestorId = (string)$eRes['data']['codigo'];
            $finalVarSku = $varSku !== '' ? $varSku : $createdEGestorId;

            // Se o SKU na Nuvemshop estava vazio, preenche com o código gerado no eGestor
            if ($varSku === '' && $createdVarId !== '') {
                $this->nuvemshop->updateVariant($createdNuvemProdId, $createdVarId, ['sku' => $finalVarSku]);
            }

            // SALVA MAPEAMENTO IMEDIATAMENTE NO BANCO LOCAL
            // Isso garante que se o webhook do eGestor disparar para este código,
            // ele já encontrará o mapeamento existente e JAMAIS criará produto avulso duplicado!
            $this->saveMapping([
                'egestor_id' => $createdEGestorId,
                'nuvemshop_product_id' => $createdNuvemProdId,
                'nuvemshop_variant_id' => $createdVarId,
                'sku' => $finalVarSku,
                'barcode' => $varBarcode,
                'name' => $varName,
                'stock_egestor' => $varStock,
                'stock_nuvemshop' => $varStock,
                'price' => $varPrice,
                'status' => 'synced',
                'last_sync_direction' => 'full_create_with_variants'
            ]);

            $mappedCount++;
        }

        $successMsg = "Produto '{$name}' criado com sucesso na Nuvemshop com grade unificada (#{$createdNuvemProdId}) e {$mappedCount} variações separadas no eGestor!";
        Logger::info($successMsg);
        Database::logSync('manual', 'full_create_with_variants', $createdNuvemProdId, $successMsg, 'success');

        return [
            'success' => true,
            'message' => $successMsg,
            'nuvemshop_product_id' => $createdNuvemProdId,
            'variants_count' => $mappedCount
        ];
    }

    /**
     * Cria um produto unificado com grade (múltiplas variações) na Nuvemshop
     * vinculando cada variação ao seu respectivo produto no eGestor.
     */
    public function createUnifiedProduct(array $data): array {
        $parentName = trim($data['name'] ?? '');
        if ($parentName === '') {
            return ['success' => false, 'error' => 'O nome principal do produto na Nuvemshop é obrigatório.'];
        }

        $attributes = $data['attributes'] ?? [];
        if (empty($attributes) || !is_array($attributes)) {
            return ['success' => false, 'error' => 'Ao menos um atributo da grade (ex: Tamanho ou Cor) deve ser definido.'];
        }

        $variantsInput = $data['variants'] ?? [];
        if (empty($variantsInput) || count($variantsInput) < 2) {
            return ['success' => false, 'error' => 'A unificação requer pelo menos 2 produtos do eGestor selecionados.'];
        }

        $nuvemVariants = [];
        $collectedImages = [];
        $preparedVariants = [];

        foreach ($variantsInput as $index => $v) {
            $egestorId = trim((string)($v['egestor_id'] ?? ''));
            if ($egestorId === '') {
                return ['success' => false, 'error' => "ID do eGestor ausente na variante #" . ($index + 1)];
            }

            // Busca os dados do produto no eGestor para garantir integridade
            $eProdRes = $this->egestor->getProduct($egestorId);
            $eProd = $eProdRes['success'] ? ($eProdRes['data'] ?? []) : [];

            $rawSku = trim((string)($v['sku'] ?? ($eProd['codigoProprio'] ?? '')));
            $sku = $rawSku !== '' ? $rawSku : $egestorId;

            $rawBarcode = trim((string)($v['barcode'] ?? ($eProd['refEanGtin'] ?? '')));
            $upperBarcode = strtoupper($rawBarcode);
            $barcode = ($rawBarcode === '' || $upperBarcode === 'SEM GTIN' || $upperBarcode === 'SEM_GTIN' || $rawBarcode === '0') ? '' : $rawBarcode;

            $stock = isset($v['stock']) ? max(0, (float)$v['stock']) : max(0, (float)($eProd['estoque'] ?? 0));
            $price = isset($v['price']) ? (float)$v['price'] : (float)($eProd['precoVenda'] ?? 0);

            // Valores dos atributos (ex: ['Verde', 'M'])
            $rawValues = (array)($v['values'] ?? []);
            $values = [];
            foreach ($rawValues as $val) {
                $values[] = ['pt' => trim((string)$val)];
            }

            if (count($values) !== count($attributes)) {
                return ['success' => false, 'error' => "A variante com código eGestor {$egestorId} deve ter exatamente " . count($attributes) . " valores de atributos preenchidos."];
            }

            // Coleta fotos do eGestor se houver
            if (!empty($eProd['listaImagens']) && is_array($eProd['listaImagens'])) {
                foreach ($eProd['listaImagens'] as $imgItem) {
                    $link = $imgItem['foto']['link'] ?? $imgItem['thumb']['link'] ?? null;
                    if ($link && !in_array($link, $collectedImages)) {
                        $collectedImages[] = $link;
                    }
                }
            }

            $nuvemVariants[] = [
                'price' => $price,
                'stock' => (int)$stock,
                'sku' => $sku,
                'barcode' => $barcode,
                'values' => $values
            ];

            $preparedVariants[] = [
                'egestor_id' => $egestorId,
                'sku' => $sku,
                'barcode' => $barcode,
                'stock' => $stock,
                'price' => $price,
                'values_text' => implode(' / ', array_map(fn($val) => is_array($val) ? ($val['pt'] ?? '') : (string)$val, $rawValues))
            ];
        }

        // Monta payload e cria o produto na Nuvemshop
        $payload = [
            'name' => $parentName,
            'description' => trim($data['description'] ?? "Produto com grade unificada a partir do eGestor PDV."),
            'attributes' => array_map(fn($a) => is_array($a) ? $a : ['pt' => trim((string)$a)], $attributes),
            'variants' => $nuvemVariants,
            'images' => $collectedImages
        ];

        Logger::info("Criando produto com grade unificada na Nuvemshop: '{$parentName}' com " . count($nuvemVariants) . " variações...");
        $createRes = $this->nuvemshop->createProduct($payload);

        if (!$createRes['success'] || empty($createRes['data']['id'])) {
            $err = $createRes['error'] ?? 'Falha ao criar produto unificado na Nuvemshop';
            Logger::error("Erro na criação de produto unificado: {$err}", ['body' => $createRes['data'] ?? []]);
            return ['success' => false, 'error' => $err];
        }

        $createdProduct = $createRes['data'];
        $createdProductId = (string)$createdProduct['id'];
        $returnedVariants = $createdProduct['variants'] ?? [];

        // Mapeia cada variação criada na Nuvemshop ao respectivo produto do eGestor
        $mappedCount = 0;
        foreach ($preparedVariants as $i => $pv) {
            $createdVariant = $returnedVariants[$i] ?? null;
            // Se por algum motivo o índice não bater, busca pelo SKU
            if (!$createdVariant || (string)($createdVariant['sku'] ?? '') !== $pv['sku']) {
                foreach ($returnedVariants as $rv) {
                    if ((string)($rv['sku'] ?? '') === $pv['sku']) {
                        $createdVariant = $rv;
                        break;
                    }
                }
            }

            $createdVarId = $createdVariant['id'] ?? null;
            $varName = $parentName . ($pv['values_text'] !== '' ? " - {$pv['values_text']}" : '');

            $this->saveMapping([
                'egestor_id' => $pv['egestor_id'],
                'nuvemshop_product_id' => $createdProductId,
                'nuvemshop_variant_id' => $createdVarId,
                'sku' => $pv['sku'],
                'barcode' => $pv['barcode'],
                'name' => $varName,
                'stock_egestor' => $pv['stock'],
                'stock_nuvemshop' => $pv['stock'],
                'price' => $pv['price'],
                'status' => 'synced',
                'last_sync_direction' => 'egestor_unified_create'
            ]);

            $mappedCount++;
        }

        $successMsg = "Produto '{$parentName}' criado na Nuvemshop com sucesso (ID #{$createdProductId}) contendo {$mappedCount} variações unificadas vinculadas ao eGestor!";
        Logger::info($successMsg);
        Database::logSync('egestor', 'unified_product_created', $createdProductId, $successMsg, 'success');

        return [
            'success' => true,
            'message' => $successMsg,
            'nuvemshop_product_id' => $createdProductId,
            'variants_count' => $mappedCount
        ];
    }
}
