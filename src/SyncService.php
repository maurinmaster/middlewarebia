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
     * Cache em memória do catálogo da Nuvemshop para validações 100% em tempo real sem banco
     */
    private ?array $nuvemshopProductsIndex = null;

    /**
     * Limpa o cache transitório da Nuvemshop
     */
    public function clearNuvemshopCache(): void {
        $this->nuvemshopProductsIndex = null;
        $cacheFile = sys_get_temp_dir() . '/bia_nuvemshop_products_cache.json';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    /**
     * Retorna o catálogo da Nuvemshop em tempo real (direto da API) indexado por SKU e Código de Barras
     */
    public function getNuvemshopProductsIndex(bool $forceRefresh = false): array {
        if ($this->nuvemshopProductsIndex !== null && !$forceRefresh) {
            return $this->nuvemshopProductsIndex;
        }

        $cacheFile = sys_get_temp_dir() . '/bia_nuvemshop_products_cache.json';
        if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < 15)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['by_sku'])) {
                $this->nuvemshopProductsIndex = $cached;
                return $cached;
            }
        }

        Logger::info("Buscando produtos em tempo real na API da Nuvemshop...");
        $allProducts = [];
        $page = 1;
        $perPage = 200;

        do {
            $res = $this->nuvemshop->getProducts($page, $perPage);
            if (!$res['success'] || !is_array($res['data'])) {
                break;
            }
            $batch = $res['data'];
            $allProducts = array_merge($allProducts, $batch);
            if (count($batch) < $perPage) {
                break;
            }
            $page++;
        } while ($page <= 10);

        $bySku = [];
        $byBarcode = [];
        $byId = [];

        foreach ($allProducts as $p) {
            $pId = (string)($p['id'] ?? '');
            $pName = is_array($p['name']) ? ($p['name']['pt'] ?? reset($p['name'])) : (string)($p['name'] ?? '');
            $byId[$pId] = $p;

            $variants = $p['variants'] ?? [];
            foreach ($variants as $v) {
                $vId = (string)($v['id'] ?? '');
                $vSku = trim((string)($v['sku'] ?? ''));
                $vBarcode = trim((string)($v['barcode'] ?? ''));
                $vStock = (float)($v['stock'] ?? 0);
                $vPrice = (float)($v['price'] ?? 0);

                $itemInfo = [
                    'product_id' => $pId,
                    'variant_id' => $vId,
                    'sku' => $vSku,
                    'barcode' => $vBarcode,
                    'stock' => $vStock,
                    'price' => $vPrice,
                    'name' => $pName
                ];

                if ($vSku !== '') {
                    $bySku[$vSku] = $itemInfo;
                }
                if ($vBarcode !== '') {
                    $byBarcode[$vBarcode] = $itemInfo;
                }
            }
        }

        $index = [
            'total' => count($allProducts),
            'products' => $allProducts,
            'by_sku' => $bySku,
            'by_barcode' => $byBarcode,
            'by_id' => $byId,
            'timestamp' => time()
        ];

        $this->nuvemshopProductsIndex = $index;
        @file_put_contents($cacheFile, json_encode($index));

        Logger::info("Catálogo Nuvemshop em tempo real carregado: " . count($allProducts) . " produtos ativos.");
        return $index;
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
            // Regra 1: Todo cadastro feito no eGestor não deve ir automaticamente para a Nuvemshop
            if ($action === 'created') {
                $msg = "Produto criado no eGestor [ID {$codigo}]. Criação automática na Nuvemshop desativada conforme regra do sistema.";
                Logger::info($msg);
                Database::logSync('egestor', 'product_created_skipped', $codigo, $msg, 'info');
                return ['success' => true, 'message' => $msg, 'skipped' => true];
            }
            return $this->syncProductFromEGestor($codigo, $action);
        }

        if ($module === 'vendas') {
            return $this->syncSaleFromEGestor($codigo);
        }

        if ($module === 'compras' || $module === 'compras_itens') {
            return $this->syncPurchaseFromEGestor($codigo);
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

            // 1. Busca se o produto existe na Nuvemshop em TEMPO REAL (sem depender de tabela de banco)
            $nuvemIndex = $this->getNuvemshopProductsIndex();
            $nuvemMatch = null;

            if ($sku !== '' && isset($nuvemIndex['by_sku'][$sku])) {
                $nuvemMatch = $nuvemIndex['by_sku'][$sku];
            } elseif (isset($nuvemIndex['by_sku'][(string)$codigo])) {
                $nuvemMatch = $nuvemIndex['by_sku'][(string)$codigo];
            } elseif ($barcode !== '' && isset($nuvemIndex['by_barcode'][$barcode])) {
                $nuvemMatch = $nuvemIndex['by_barcode'][$barcode];
            }

            // Fallback: se não estiver no índice em memória, busca direto na API da Nuvemshop
            if (!$nuvemMatch) {
                $searchResult = $this->nuvemshop->findProductVariantBySku($sku);
                if (!$searchResult && $barcode !== '') {
                    $searchResult = $this->nuvemshop->findProductVariantByBarcode($barcode);
                }
                if ($searchResult) {
                    $nuvemMatch = [
                        'product_id' => (string)$searchResult['product']['id'],
                        'variant_id' => (string)$searchResult['variant']['id'],
                        'sku' => $sku,
                        'name' => $nome
                    ];
                }
            }

            if ($nuvemMatch) {
                $nuvemProductId = $nuvemMatch['product_id'];
                $nuvemVariantId = $nuvemMatch['variant_id'];

                // Produto existe na Nuvemshop -> Atualiza Estoque e Preço
                $updateRes = $this->nuvemshop->updateVariantStock($nuvemProductId, $nuvemVariantId, (int)$estoque);
                if ($precoVenda > 0) {
                    $this->nuvemshop->updateVariantPrice($nuvemProductId, $nuvemVariantId, $precoVenda);
                }

                // Se retornar 404 (Not Found), o produto foi excluído da Nuvemshop
                if (!$updateRes['success'] && (($updateRes['status'] ?? 0) === 404 || str_contains($updateRes['error'] ?? '', 'Not Found'))) {
                    $this->clearNuvemshopCache();
                    $msg = "Produto do eGestor [ID {$codigo} - '{$nome}'] não existe na Nuvemshop (Not Found). Nenhuma ação necessária.";
                    Logger::info($msg);
                    Database::logSync('egestor', 'unmapped_product_skipped', $codigo, $msg, 'info');
                    return ['success' => true, 'message' => $msg, 'skipped' => true];
                }

                if ($updateRes['success']) {
                    $status = 'synced';
                    $msg = "Estoque sincronizado na Nuvemshop com sucesso: {$estoque} un.";
                    Database::logSync('egestor', 'stock_updated', $codigo, "Produto '{$nome}' (SKU {$sku}) atualizado para {$estoque} un na Nuvemshop", 'success');
                    $this->clearNuvemshopCache();
                    return ['success' => true, 'message' => $msg, 'nuvemshop_product_id' => $nuvemProductId];
                } else {
                    $status = 'error';
                    $msg = "Erro ao atualizar estoque na Nuvemshop: " . ($updateRes['error'] ?? 'desconhecido');
                    Database::logSync('egestor', 'stock_update_failed', $codigo, $msg, 'error');
                    return ['success' => false, 'error' => $msg];
                }
            } else {
                // Se NÃO foi encontrado mapeamento na Nuvemshop:
                // Se for webhook automático do eGestor ($forceCreate === false), NUNCA cria produto avulso automaticamente!
                // O lojista cadastra produtos pela tela de cadastro (com grade) ou pelo botão manual "Enviar para Nuvem" no Vizor.
                if (!$forceCreate) {
                    $msg = "Produto do eGestor [ID {$codigo} - '{$nome}'] não está na Nuvemshop. Ignorando atualização (regra: apenas produtos presentes na Nuvemshop são atualizados).";
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
     * Sincroniza venda efetuada no eGestor (quando uma venda é feita no balcão/PDV)
     * Regra 4: Toda compra/venda efetuada no eGestor contabiliza no estoque da Nuvemshop (caso o produto exista na Nuvem)
     */
    public function syncSaleFromEGestor(string $codigoVenda): array {
        Logger::info("Venda realizada no PDV eGestor [Venda ID: {$codigoVenda}]. Sincronizando estoques relacionados na Nuvemshop...");
        $synced = 0;

        try {
            $saleRes = $this->egestor->getSale($codigoVenda);
            if ($saleRes['success'] && !empty($saleRes['data']['produtos'])) {
                foreach ($saleRes['data']['produtos'] as $item) {
                    $codProd = $item['codProduto'] ?? null;
                    if ($codProd) {
                        $syncRes = $this->syncProductFromEGestor((string)$codProd, 'sale_stock_sync', false);
                        if (!empty($syncRes['success']) && empty($syncRes['skipped'])) {
                            $synced++;
                        }
                    }
                }
                $msg = "Venda no PDV concluída. {$synced} produto(s) atualizados na Nuvemshop.";
                Database::logSync('egestor', 'sale_completed', $codigoVenda, $msg, 'success');
                return ['success' => true, 'message' => $msg, 'synced_items' => $synced];
            }
        } catch (\Throwable $e) {
            Logger::error("Erro ao sincronizar produtos da venda #{$codigoVenda}: " . $e->getMessage());
        }

        Database::logSync('egestor', 'sale_completed', $codigoVenda, "Venda no PDV registrada. Aguardando webhooks de produtos para baixa fina.", 'success');
        return ['success' => true, 'message' => 'Venda registrada com sucesso'];
    }

    /**
     * Sincroniza compra/entrada efetuada no eGestor (compra de fornecedor)
     * Regra 4: Toda compra efetuada no eGestor contabiliza no estoque da Nuvemshop (caso o produto exista na Nuvem)
     */
    public function syncPurchaseFromEGestor(string $codigoCompra): array {
        Logger::info("Compra efetuada no eGestor [Compra ID: {$codigoCompra}]. Sincronizando estoques relacionados na Nuvemshop...");
        $synced = 0;

        try {
            $purchaseRes = $this->egestor->getPurchase($codigoCompra);
            if ($purchaseRes['success'] && !empty($purchaseRes['data']['produtos'])) {
                foreach ($purchaseRes['data']['produtos'] as $item) {
                    $codProd = $item['codProduto'] ?? null;
                    if ($codProd) {
                        $syncRes = $this->syncProductFromEGestor((string)$codProd, 'purchase_stock_sync', false);
                        if (!empty($syncRes['success']) && empty($syncRes['skipped'])) {
                            $synced++;
                        }
                    }
                }
                $msg = "Compra/Entrada no eGestor concluída. {$synced} produto(s) atualizados na Nuvemshop.";
                Database::logSync('egestor', 'purchase_completed', $codigoCompra, $msg, 'success');
                return ['success' => true, 'message' => $msg, 'synced_items' => $synced];
            }
        } catch (\Throwable $e) {
            Logger::error("Erro ao sincronizar produtos da compra #{$codigoCompra}: " . $e->getMessage());
        }

        Database::logSync('egestor', 'purchase_completed', $codigoCompra, "Compra/Entrada no eGestor registrada.", 'success');
        return ['success' => true, 'message' => 'Compra registrada com sucesso'];
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
            $saleResult = null;
            $saleCreatedInEGestor = false;

            // Se for venda confirmada (e não cancelamento), cadastra o cliente e lança a Venda no eGestor para emissão de NF-e
            // NOTA CRÍTICA: Ao registrar uma Venda (POST /vendas) no eGestor, o próprio eGestor já debita automaticamente
            // o estoque de cada produto vendido! Portanto, se a venda for criada com sucesso, NUNCA devemos chamar updateProductStock
            // novamente, pois isso causaria dupla baixa (fazendo o estoque ir de 1 para -1 em vez de ir para 0).
            if (!$isCancelled) {
                try {
                    $saleResult = $this->syncOrderCustomerAndSaleToEGestor($payload, $orderId);
                    $saleCreatedInEGestor = !empty($saleResult['success']) && !empty($saleResult['sale_id']);
                    if ($saleCreatedInEGestor) {
                        Logger::info("Venda oficial #{$saleResult['sale_id']} criada no eGestor com sucesso. O eGestor já efetuou a baixa automática de estoque.");
                    }
                } catch (\Throwable $errSale) {
                    Logger::error("Erro ao registrar cliente/venda no eGestor para pedido #{$orderId}: " . $errSale->getMessage());
                }
            }

            foreach ($products as $item) {
                $sku = trim((string)($item['sku'] ?? ''));
                $barcode = trim((string)($item['barcode'] ?? ''));
                $qty = (float)($item['quantity'] ?? 1);
                $nuvemProdId = $item['product_id'] ?? null;
                $nuvemVarId = $item['variant_id'] ?? null;
                $prodName = $item['name'] ?? 'Produto';

                $egestorId = null;

                $egestorId = null;

                // Busca item diretamente na API do eGestor em tempo real:
                // 1º Por SKU
                if ($sku !== '') {
                    $egestorProd = $this->egestor->findProductBySku($sku);
                    if ($egestorProd && !empty($egestorProd['codigo'])) {
                        $egestorId = (string)$egestorProd['codigo'];
                    }
                }

                // 2º Por Código de Barras
                if (!$egestorId && $barcode !== '') {
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
                            // Cancelamento: estorna a quantidade devolvendo ao estoque no eGestor
                            $newStock = $currentStock + $qty;
                            $actionName = 'order_stock_restored';
                            $actionMsg = "Pedido Nuvemshop #{$orderId} CANCELADO: Estoque de {$prodName} (SKU {$sku}) devolvido de {$currentStock} para {$newStock} no eGestor";
                            $updateRes = $this->egestor->updateProductStock($egestorId, $newStock);
                            if ($updateRes['success']) {
                                Logger::info($actionMsg);
                                Database::logSync('nuvemshop', $actionName, (string)$orderId, $actionMsg, 'success');
                                $syncedItems++;
                            }
                        } else {
                            if ($saleCreatedInEGestor) {
                                // A Venda já foi lançada no eGestor e o eGestor já debitou automaticamente a quantidade do estoque!
                                // NÃO chamamos updateProductStock para não debitar uma 2ª vez (evita ir de 1 para -1)!
                                $newStock = $currentStock; // o estoque atual já reflete a dedução da venda
                                $actionName = 'order_stock_deducted';
                                $actionMsg = "Venda Nuvemshop #{$orderId}: Venda oficial lançada no eGestor (baixa de {$qty} un efetuada). Estoque atual no eGestor: {$newStock} un";
                                Logger::info($actionMsg);
                                Database::logSync('nuvemshop', $actionName, (string)$orderId, $actionMsg, 'success');
                                $syncedItems++;
                            } else {
                                // Fallback: A criação da venda no eGestor falhou ou não pôde ser completada.
                                // Baixamos o estoque manualmente para não deixar o estoque descoberto.
                                $newStock = max(0, $currentStock - $qty);
                                $actionName = 'order_stock_deducted';
                                $actionMsg = "Venda Nuvemshop #{$orderId} (baixa manual): Estoque de {$prodName} (SKU {$sku}) baixado de {$currentStock} para {$newStock} no eGestor";
                                $updateRes = $this->egestor->updateProductStock($egestorId, $newStock);
                                if ($updateRes['success']) {
                                    Logger::info($actionMsg);
                                    Database::logSync('nuvemshop', $actionName, (string)$orderId, $actionMsg, 'success');
                                    $syncedItems++;
                                }
                            }
                        }

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
                    }
                } else {
                    $warnMsg = "Venda Nuvemshop #{$orderId}: Item '{$prodName}' (SKU: '{$sku}') não foi encontrado no eGestor.";
                    Logger::warning($warnMsg);
                    Database::logSync('nuvemshop', 'unmapped_product_sale', (string)$orderId, $warnMsg, 'warning');
                }
            }

            return [
                'success' => true,
                'synced_items' => $syncedItems,
                'order_id' => $orderId,
                'sale_sync' => $saleResult
            ];
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
     * Salva ou atualiza mapeamento (desativado para não armazenar produtos no banco local)
     * Conforme solicitação: dados ficam 100% em tempo real via APIs do eGestor e Nuvemshop.
     */
    private function saveMapping(array $data): void {
        // Limpa cache transitório em memória/disco para refletir a alteração imediatamente
        $this->clearNuvemshopCache();
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

            // 1. Tenta achar na Nuvemshop em tempo real (via busca direta por SKU/Barcode)
            $search = $this->nuvemshop->findProductVariantBySku($sku);
            if (!$search && $barcode !== '') {
                $search = $this->nuvemshop->findProductVariantByBarcode($barcode);
            }
            if (!$search && $sku !== (string)$codigo) {
                $search = $this->nuvemshop->findProductVariantBySku((string)$codigo);
            }

            $nuvemProductId = $search ? (string)$search['product']['id'] : null;
            $nuvemVariantId = $search ? (string)$search['variant']['id'] : null;

            if ($nuvemProductId && $nuvemVariantId) {
                // Produto JÁ existe na Nuvemshop -> Atualiza estoque e preço
                $upStock = $this->nuvemshop->updateVariantStock($nuvemProductId, $nuvemVariantId, (int)$estoque);
                if ($precoVenda > 0) {
                    $this->nuvemshop->updateVariantPrice($nuvemProductId, $nuvemVariantId, $precoVenda);
                }
                // Se o produto foi deletado na Nuvemshop (404 Not Found)
                if (!$upStock['success'] && (($upStock['status'] ?? 0) === 404 || str_contains($upStock['error'] ?? '', 'Not Found'))) {
                    $skipped++;
                    $itemsLog[] = "Ignorado (produto não existe na Nuvemshop): {$nome}";
                    continue;
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
     * Formata a descrição da Origem Fiscal da mercadoria (SEFAZ / NF-e)
     */
    public static function formatOrigemFiscal($origem): string {
        $origem = trim((string)$origem);
        $map = [
            '0' => '0 - Nacional',
            '1' => '1 - Estrangeira (Importação direta)',
            '2' => '2 - Estrangeira (Adquirida no mercado interno)',
            '3' => '3 - Nacional (Conteúdo de Importação > 40% e <= 70%)',
            '4' => '4 - Nacional (Processos produtivos básicos)',
            '5' => '5 - Nacional (Conteúdo de Importação <= 40%)',
            '6' => '6 - Estrangeira (Importação direta s/ similar nacional)',
            '7' => '7 - Estrangeira (Mercado interno s/ similar nacional)',
            '8' => '8 - Nacional (Conteúdo de Importação > 70%)'
        ];
        return $map[$origem] ?? ($origem !== '' ? "Origem {$origem}" : '0 - Nacional');
    }

    /**
     * Vizor: Compara catálogo do eGestor com a Nuvemshop.
     * Retorna produtos do eGestor indicando quais já estão na Nuvemshop e quais ainda não foram enviados,
     * incluindo todos os dados fiscais (NCM, CEST, Origem, GTIN, Unidade, Custo, etc.).
     */
    public function getEGestorVsNuvemProducts(int $page = 1, string $filter = '', string|bool $statusFilter = 'all', string $stockFilter = 'all'): array {
        try {
            // Normaliza statusFilter
            if (is_bool($statusFilter)) {
                $statusFilter = $statusFilter ? 'missing' : 'all';
            }

            // Obtém índice atualizado em tempo real da Nuvemshop
            $nuvemIndex = $this->getNuvemshopProductsIndex();

            // CASO 1: Filtrar apenas os produtos JÁ CADASTRADOS na Nuvemshop
            // Carrega em TEMPO REAL direto da Nuvemshop (sem dados fantasmas no banco)
            if ($statusFilter === 'in_nuvem') {
                $rawNuvemProducts = $nuvemIndex['products'];
                $filteredItems = [];

                foreach ($rawNuvemProducts as $p) {
                    $pId = (string)($p['id'] ?? '');
                    $pName = is_array($p['name']) ? ($p['name']['pt'] ?? reset($p['name'])) : (string)($p['name'] ?? '');
                    $variants = $p['variants'] ?? [];

                    foreach ($variants as $v) {
                        $vSku = trim((string)($v['sku'] ?? ''));
                        $vBarcode = trim((string)($v['barcode'] ?? ''));
                        $vStock = (float)($v['stock'] ?? 0);
                        $vPrice = (float)($v['price'] ?? 0);

                        // Filtro de texto (nome, SKU, barcode, ID)
                        if ($filter !== '') {
                            $matchText = (stripos($pName, $filter) !== false) ||
                                         ($vSku !== '' && stripos($vSku, $filter) !== false) ||
                                         ($vBarcode !== '' && stripos($vBarcode, $filter) !== false) ||
                                         (stripos($pId, $filter) !== false);
                            if (!$matchText) {
                                continue;
                            }
                        }

                        // Filtro de estoque na Nuvemshop
                        if ($stockFilter === 'has_stock' && $vStock <= 0) {
                            continue;
                        } elseif ($stockFilter === 'zero_stock' && $vStock > 0) {
                            continue;
                        } elseif ($stockFilter === 'low_stock' && ($vStock <= 0 || $vStock > 3)) {
                            continue;
                        }

                        $filteredItems[] = [
                            'sku' => $vSku,
                            'barcode' => $vBarcode,
                            'pId' => $pId,
                            'vId' => (string)($v['id'] ?? ''),
                            'pName' => $pName,
                            'vStock' => $vStock,
                            'vPrice' => $vPrice
                        ];
                    }
                }

                $totalNuvem = count($filteredItems);
                $perPage = 50;
                $lastPage = max(1, (int)ceil($totalNuvem / $perPage));
                $offset = ($page - 1) * $perPage;
                $pagedSlice = array_slice($filteredItems, $offset, $perPage);

                $items = [];
                foreach ($pagedSlice as $sliceItem) {
                    $sku = $sliceItem['sku'];
                    $barcode = $sliceItem['barcode'];
                    $pId = $sliceItem['pId'];
                    $vId = $sliceItem['vId'];
                    $pName = $sliceItem['pName'];
                    $vStock = $sliceItem['vStock'];
                    $vPrice = $sliceItem['vPrice'];

                    // Busca em tempo real no eGestor para obter dados fiscais e estoque no PDV
                    $eProd = null;
                    if ($sku !== '') {
                        $eProd = $this->egestor->findProductBySku($sku);
                    }
                    if (!$eProd && $barcode !== '') {
                        $eProd = $this->egestor->findProductByBarcode($barcode);
                    }

                    $eCodigo = $eProd ? (string)($eProd['codigo'] ?? $sku) : $sku;
                    $eEstoque = $eProd ? (float)($eProd['estoque'] ?? 0) : 0.0;
                    $ncm = trim((string)($eProd['ncm'] ?? ''));
                    $cest = trim((string)($eProd['codigoCEST'] ?? ''));
                    $origemFiscal = trim((string)($eProd['origemFiscal'] ?? '0'));
                    $unidade = trim((string)($eProd['unidadeTributada'] ?? 'UN'));
                    $precoCusto = (float)($eProd['precoCusto'] ?? 0);
                    $gtin = trim((string)($eProd['refEanGtin'] ?? $barcode));
                    $gtinUpper = strtoupper($gtin);
                    $gtinTributavel = ($gtin === '' || $gtinUpper === 'SEM GTIN' || $gtinUpper === 'SEM_GTIN' || $gtin === '0') ? '' : $gtin;

                    $items[] = [
                        'codigo' => $eCodigo ?: $sku,
                        'sku' => $sku ?: $eCodigo,
                        'nome' => $pName,
                        'barcode' => $barcode,
                        'estoque' => $eEstoque,
                        'preco' => $vPrice,
                        'in_nuvemshop' => true,
                        'nuvemshop_product_id' => $pId,
                        'nuvemshop_variant_id' => $vId,
                        'stock_nuvemshop' => $vStock,
                        // DADOS FISCAIS EM TEMPO REAL
                        'ncm' => $ncm,
                        'cest' => $cest,
                        'origem_fiscal' => $origemFiscal,
                        'origem_fiscal_desc' => self::formatOrigemFiscal($origemFiscal),
                        'unidade_tributada' => $unidade ?: 'UN',
                        'preco_custo' => $precoCusto,
                        'gtin_tributavel' => $gtinTributavel,
                        'grupo_tributos' => trim((string)($eProd['codigoGrupoTributos'] ?? '')),
                        'beneficio_fiscal' => trim((string)($eProd['codigoBeneficioFiscal'] ?? '')),
                        'excecao_ipi' => trim((string)($eProd['excecaoIPI'] ?? '')),
                        'anotacoes_nfe' => trim((string)($eProd['anotacoesNFE'] ?? '')),
                        'peso_liquido' => (float)($eProd['pesoLiquido'] ?? 0),
                        'peso_bruto' => (float)($eProd['pesoBruto'] ?? 0)
                    ];
                }

                return [
                    'success' => true,
                    'page' => $page,
                    'last_page' => $lastPage,
                    'total_egestor' => $totalNuvem,
                    'items' => $items
                ];
            }

            // CASO 2: Catálogo do eGestor ('all' ou 'missing')
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

                // Checagem em TEMPO REAL contra a Nuvemshop (sem tabela de banco desatualizada)
                $nuvemMatch = null;
                if ($sku !== '' && isset($nuvemIndex['by_sku'][$sku])) {
                    $nuvemMatch = $nuvemIndex['by_sku'][$sku];
                } elseif (isset($nuvemIndex['by_sku'][$codigo])) {
                    $nuvemMatch = $nuvemIndex['by_sku'][$codigo];
                } elseif ($barcode !== '' && isset($nuvemIndex['by_barcode'][$barcode])) {
                    $nuvemMatch = $nuvemIndex['by_barcode'][$barcode];
                }

                $inNuvem = !empty($nuvemMatch);

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

                // DADOS FISCAIS COMPLETOS DO EGESTOR
                $ncm = trim((string)($p['ncm'] ?? ''));
                $cest = trim((string)($p['codigoCEST'] ?? ''));
                $origemFiscal = trim((string)($p['origemFiscal'] ?? '0'));
                $unidade = trim((string)($p['unidadeTributada'] ?? 'UN'));
                $precoCusto = (float)($p['precoCusto'] ?? 0);
                $gtin = trim((string)($p['refEanGtin'] ?? ''));
                $gtinUpper = strtoupper($gtin);
                $gtinTributavel = ($gtin === '' || $gtinUpper === 'SEM GTIN' || $gtinUpper === 'SEM_GTIN' || $gtin === '0') ? '' : $gtin;
                $grupoTributos = trim((string)($p['codigoGrupoTributos'] ?? ''));
                $beneficioFiscal = trim((string)($p['codigoBeneficioFiscal'] ?? ''));
                $excecaoIPI = trim((string)($p['excecaoIPI'] ?? ''));
                $anotacoesNfe = trim((string)($p['anotacoesNFE'] ?? ''));
                $pesoLiquido = (float)($p['pesoLiquido'] ?? 0);
                $pesoBruto = (float)($p['pesoBruto'] ?? 0);

                $items[] = [
                    'codigo' => $codigo,
                    'sku' => $sku !== '' ? $sku : $codigo,
                    'nome' => $nome,
                    'barcode' => $barcode,
                    'estoque' => $estoque,
                    'preco' => $preco,
                    'in_nuvemshop' => $inNuvem,
                    'nuvemshop_product_id' => $nuvemMatch['product_id'] ?? null,
                    'nuvemshop_variant_id' => $nuvemMatch['variant_id'] ?? null,
                    'stock_nuvemshop' => $nuvemMatch['stock'] ?? null,
                    // DADOS FISCAIS
                    'ncm' => $ncm,
                    'cest' => $cest,
                    'origem_fiscal' => $origemFiscal,
                    'origem_fiscal_desc' => self::formatOrigemFiscal($origemFiscal),
                    'unidade_tributada' => $unidade ?: 'UN',
                    'preco_custo' => $precoCusto,
                    'gtin_tributavel' => $gtinTributavel,
                    'grupo_tributos' => $grupoTributos,
                    'beneficio_fiscal' => $beneficioFiscal,
                    'excecao_ipi' => $excecaoIPI,
                    'anotacoes_nfe' => $anotacoesNfe,
                    'peso_liquido' => $pesoLiquido,
                    'peso_bruto' => $pesoBruto
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

        // Extrai dados fiscais informados para a emissão de NF-e no eGestor
        $fiscal = (array)($data['fiscal'] ?? []);
        $fiscalData = [
            'ncm' => $fiscal['ncm'] ?? ($data['ncm'] ?? null),
            'codigoCEST' => $fiscal['cest'] ?? ($fiscal['codigoCEST'] ?? ($data['cest'] ?? null)),
            'origemFiscal' => isset($fiscal['origem_fiscal']) ? $fiscal['origem_fiscal'] : (isset($fiscal['origemFiscal']) ? $fiscal['origemFiscal'] : ($data['origem_fiscal'] ?? null)),
            'unidadeTributada' => $fiscal['unidade_tributada'] ?? ($fiscal['unidadeTributada'] ?? ($data['unidade_tributada'] ?? null)),
            'codigoGrupoTributos' => $fiscal['grupo_tributos'] ?? ($fiscal['codigoGrupoTributos'] ?? ($data['grupo_tributos'] ?? null)),
            'codigoBeneficioFiscal' => $fiscal['beneficio_fiscal'] ?? ($fiscal['codigoBeneficioFiscal'] ?? ($data['beneficio_fiscal'] ?? null)),
            'anotacoesNFE' => $fiscal['anotacoes_nfe'] ?? ($fiscal['anotacoesNFE'] ?? ($data['anotacoes_nfe'] ?? null)),
            'pesoLiquido' => $fiscal['peso_liquido'] ?? ($fiscal['pesoLiquido'] ?? ($data['peso_liquido'] ?? null)),
            'pesoBruto' => $fiscal['peso_bruto'] ?? ($fiscal['pesoBruto'] ?? ($data['peso_bruto'] ?? null))
        ];
        $fiscalData = array_filter($fiscalData, fn($val) => $val !== null && $val !== '');

        // CASO 1: PRODUTO SIMPLES (Sem Variações)
        if (!$hasVariants) {
            $stock = (float)($data['stock'] ?? 0);
            $sku = trim((string)($data['sku'] ?? ''));
            $barcode = trim((string)($data['barcode'] ?? ''));

            // 1. Cria primeiro no eGestor com dados fiscais
            $eData = array_merge([
                'descricao' => $name,
                'precoVenda' => $price,
                'precoCusto' => $costPrice,
                'estoque' => $stock,
                'codigoProprio' => $sku,
                'refEanGtin' => $barcode,
                'anotacoesInternas' => "Cadastrado via Middleware Bia"
            ], $fiscalData);

            Logger::info("Cadastrando produto simples no eGestor: '{$name}' com dados fiscais...", ['fiscal' => $fiscalData]);
            $eRes = $this->egestor->createProduct($eData);
            if (!$eRes['success'] || empty($eRes['data']['codigo'])) {
                $err = $eRes['error'] ?? 'Falha ao cadastrar produto no eGestor';
                Logger::error("Erro no cadastro eGestor: {$err}");
                return ['success' => false, 'error' => "Falha no eGestor: {$err}"];
            }

            $egestorId = (string)$eRes['data']['codigo'];
            $finalSku = $sku !== '' ? $sku : $egestorId;

            // 2. Cria na Nuvemshop já com o SKU e estoque definitivo
            $nuvemPayload = [
                'name' => $name,
                'description' => $description,
                'price' => $price,
                'stock' => (int)$stock,
                'sku' => $finalSku,
                'barcode' => $barcode,
                'images' => $images
            ];

            Logger::info("Cadastrando produto simples na Nuvemshop: '{$name}' (SKU: {$finalSku})...");
            $nRes = $this->nuvemshop->createProduct($nuvemPayload);
            if (!$nRes['success'] || empty($nRes['data']['id'])) {
                $err = $nRes['error'] ?? 'Falha ao cadastrar produto na Nuvemshop';
                Logger::error("Erro no cadastro Nuvemshop: {$err}");
                return ['success' => false, 'error' => "Criado no eGestor (#{$egestorId}), mas falhou na Nuvemshop: {$err}"];
            }

            $nuvemshopProdId = (string)$nRes['data']['id'];
            $nuvemshopVarId = (string)($nRes['data']['variants'][0]['id'] ?? '');

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

            Database::logSync('manual', 'full_create_simple', $egestorId, "Produto simples '{$name}' criado no eGestor (#{$egestorId}) e Nuvemshop (#{$nuvemshopProdId})", 'success');

            return [
                'success' => true,
                'message' => "Produto '{$name}' cadastrado com sucesso no eGestor e na Nuvemshop!",
                'nuvemshop_product_id' => $nuvemshopProdId,
                'egestor_id' => $egestorId
            ];
        }

        // CASO 2: PRODUTO COM VARIAÇÕES (GRADE: COR / TAMANHO)
        $attributes = (array)($data['attributes'] ?? []);
        if (empty($attributes)) {
            $attributes = ['Cor', 'Tamanho'];
        }

        $variants = (array)$data['variants'];
        if (count($variants) < 1) {
            return ['success' => false, 'error' => 'Nenhuma variação informada para a grade.'];
        }

        Logger::info("Iniciando cadastro completo com grade para '{$name}' (" . count($variants) . " variações)...");

        // 1. CRIA PRIMEIRO CADA VARIAÇÃO SEPARADA NO EGESTOR PARA OBTER OS CÓDIGOS REAIS
        $preparedVariants = [];
        $nuvemVariants = [];

        foreach ($variants as $idx => $v) {
            $rawValues = (array)($v['values'] ?? []);
            $valuesText = implode(' - ', array_map('trim', $rawValues));
            $varName = $name . ($valuesText !== '' ? " - {$valuesText}" : " - Var " . ($idx + 1));
            $varPrice = isset($v['price']) && $v['price'] !== '' ? (float)$v['price'] : $price;
            $varCost = isset($v['cost_price']) && $v['cost_price'] !== '' ? (float)$v['cost_price'] : $costPrice;
            $varStock = isset($v['stock']) && $v['stock'] !== '' ? (float)$v['stock'] : 0.0;
            $varSku = trim((string)($v['sku'] ?? ''));
            $varBarcode = trim((string)($v['barcode'] ?? ''));

            // Cria o produto individual no eGestor (PDV) com dados fiscais
            $eData = array_merge([
                'descricao' => $varName,
                'precoVenda' => $varPrice,
                'precoCusto' => $varCost,
                'estoque' => $varStock,
                'codigoProprio' => $varSku,
                'refEanGtin' => $varBarcode,
                'anotacoesInternas' => "Grade: " . implode(' / ', $rawValues)
            ], $fiscalData);

            Logger::info("Criando variação no eGestor: '{$varName}' (Estoque: {$varStock})...");
            $eRes = $this->egestor->createProduct($eData);
            if (!$eRes['success'] || empty($eRes['data']['codigo'])) {
                $err = $eRes['error'] ?? 'Falha ao criar item no eGestor';
                Logger::error("Erro ao cadastrar variação no eGestor [{$varName}]: {$err}");
                return ['success' => false, 'error' => "Falha ao cadastrar variação '{$varName}' no eGestor: {$err}"];
            }

            $createdEGestorId = (string)$eRes['data']['codigo'];
            $finalVarSku = $varSku !== '' ? $varSku : $createdEGestorId;

            $nuvemValues = [];
            foreach ($rawValues as $val) {
                $nuvemValues[] = ['pt' => trim((string)$val)];
            }

            // Monta variante para a Nuvemshop já com SKU final, preço e estoque exatos
            $nuvemVariants[] = [
                'price' => $varPrice,
                'stock' => (int)$varStock,
                'sku' => $finalVarSku,
                'barcode' => $varBarcode,
                'values' => $nuvemValues
            ];

            $preparedVariants[] = [
                'egestor_id' => $createdEGestorId,
                'sku' => $finalVarSku,
                'barcode' => $varBarcode,
                'stock' => $varStock,
                'price' => $varPrice,
                'var_name' => $varName,
                'raw_values' => $rawValues
            ];
        }

        // 2. CRIA NA NUVEMSHOP O PRODUTO UNIFICADO COM A GRADE COMPLETA EM UMA ÚNICA REQUISIÇÃO ATÔMICA
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
            return ['success' => false, 'error' => "Variações salvas no eGestor, mas falhou ao criar produto na Nuvemshop: {$err}"];
        }

        $createdNuvemProduct = $nRes['data'];
        $createdNuvemProdId = (string)$createdNuvemProduct['id'];
        $returnedVariants = $createdNuvemProduct['variants'] ?? [];

        // 3. REGISTRA OS MAPEAMENTOS IMEDIATAMENTE (SEM NECESSIDADE DE PUT SECUNDÁRIO)
        $mappedCount = 0;
        foreach ($preparedVariants as $idx => $pv) {
            $createdVariant = $returnedVariants[$idx] ?? null;
            // Se o índice não coincidir ou SKU for diferente, localiza pelo SKU ou values
            if (!$createdVariant || (string)($createdVariant['sku'] ?? '') !== $pv['sku']) {
                foreach ($returnedVariants as $rv) {
                    if ((string)($rv['sku'] ?? '') === $pv['sku']) {
                        $createdVariant = $rv;
                        break;
                    }
                }
            }

            $createdVarId = (string)($createdVariant['id'] ?? '');

            $this->saveMapping([
                'egestor_id' => $pv['egestor_id'],
                'nuvemshop_product_id' => $createdNuvemProdId,
                'nuvemshop_variant_id' => $createdVarId,
                'sku' => $pv['sku'],
                'barcode' => $pv['barcode'],
                'name' => $pv['var_name'],
                'stock_egestor' => $pv['stock'],
                'stock_nuvemshop' => $pv['stock'],
                'price' => $pv['price'],
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

    /**
     * Coleta os dados do cliente e da compra da Nuvemshop e lança no eGestor
     * Cria ou atualiza o Contato e registra a Venda pronta para emissão de NF-e (Opção A)
     */
    public function syncOrderCustomerAndSaleToEGestor(array $order, string $orderId): array {
        $db = Database::getConnection();

        // 1. Extração dos dados do cliente
        $customer = $order['customer'] ?? [];
        $billing = $order['billing_address'] ?? [];
        $shipping = $order['shipping_address'] ?? [];

        $customerName = trim((string)($customer['name'] ?? ''));
        if (empty($customerName)) {
            $firstName = trim((string)($billing['first_name'] ?? $shipping['first_name'] ?? ''));
            $lastName = trim((string)($billing['last_name'] ?? $shipping['last_name'] ?? ''));
            $customerName = trim("{$firstName} {$lastName}");
        }
        if (empty($customerName)) {
            $customerName = "Cliente Nuvemshop #{$orderId}";
        }

        $rawCpf = (string)($customer['identification'] ?? $billing['identification'] ?? $shipping['identification'] ?? '');
        $cleanCpf = preg_replace('/\D/', '', $rawCpf);

        $email = trim((string)($customer['email'] ?? $order['contact_email'] ?? ''));
        $phone = (string)($billing['phone'] ?? $customer['phone'] ?? $shipping['phone'] ?? '');

        // Endereço preferencialmente de faturamento (billing), com fallback para entrega (shipping)
        $addrSource = !empty($billing['address']) ? $billing : $shipping;
        $logradouro = trim((string)($addrSource['address'] ?? ''));
        $numero = trim((string)($addrSource['number'] ?? 'S/N'));
        $complemento = trim((string)($addrSource['floor'] ?? ''));
        $bairro = trim((string)($addrSource['locality'] ?? ''));
        $cidade = trim((string)($addrSource['city'] ?? ''));
        $uf = strtoupper(trim((string)($addrSource['state'] ?? '')));
        $cep = preg_replace('/\D/', '', (string)($addrSource['zipcode'] ?? ''));

        // 2. Busca ou criação do contato no eGestor
        $contactId = null;
        if (!empty($cleanCpf)) {
            $existing = $this->egestor->findContactByCpf($cleanCpf);
            if ($existing && !empty($existing['codigo'])) {
                $contactId = (int)$existing['codigo'];
                Logger::info("Cliente já cadastrado no eGestor localizado por CPF ({$cleanCpf}): ID {$contactId}");
            }
        }

        if (!$contactId && !empty($customerName)) {
            $existingByName = $this->egestor->findContactByName($customerName);
            if ($existingByName && !empty($existingByName['codigo'])) {
                $contactId = (int)$existingByName['codigo'];
                Logger::info("Cliente já cadastrado no eGestor localizado por nome ('{$customerName}'): ID {$contactId}");
            }
        }

        if (!$contactId) {
            Logger::info("Cadastrando novo cliente no eGestor para pedido #{$orderId}...");
            $createContactRes = $this->egestor->createContact([
                'nome' => $customerName,
                'cpfcnpj' => $cleanCpf,
                'email' => $email,
                'phone' => $phone,
                'logradouro' => $logradouro,
                'numero' => $numero,
                'complemento' => $complemento,
                'bairro' => $bairro,
                'uf' => $uf,
                'cep' => $cep,
                'obs' => "Cadastrado via integração Nuvemshop (Pedido #{$orderId})"
            ]);

            if ($createContactRes['success'] && !empty($createContactRes['data']['codigo'])) {
                $contactId = (int)$createContactRes['data']['codigo'];
                Logger::info("Novo cliente criado com sucesso no eGestor! ID: {$contactId}");
            } elseif ($createContactRes['success'] && !empty($createContactRes['data']['id'])) {
                $contactId = (int)$createContactRes['data']['id'];
                Logger::info("Novo cliente criado com sucesso no eGestor! ID: {$contactId}");
            } else {
                $err = $createContactRes['error'] ?? 'Falha ao cadastrar cliente no eGestor';
                Logger::error("Erro ao cadastrar cliente no eGestor: {$err}", ['body' => $createContactRes['data'] ?? []]);
            }
        }

        // 3. Montagem dos itens da venda para o eGestor
        $saleProducts = [];
        $products = $order['products'] ?? [];
        $totalItems = count($products);
        $totalDiscount = (float)($order['discount'] ?? 0);
        $itemDiscount = ($totalItems > 0 && $totalDiscount > 0) ? round($totalDiscount / $totalItems, 2) : 0;

        foreach ($products as $item) {
            $sku = trim((string)($item['sku'] ?? ''));
            $barcode = trim((string)($item['barcode'] ?? ''));
            $qty = (float)($item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $nuvemVarId = $item['variant_id'] ?? null;
            $nuvemProdId = $item['product_id'] ?? null;
            $egestorId = null;

            // Busca item no eGestor diretamente em tempo real por SKU ou Código de Barras
            if ($sku !== '') {
                $eP = $this->egestor->findProductBySku($sku);
                if ($eP && !empty($eP['codigo'])) {
                    $egestorId = (string)$eP['codigo'];
                }
            }

            if (!$egestorId && $barcode !== '') {
                $eP = $this->egestor->findProductByBarcode($barcode);
                if ($eP && !empty($eP['codigo'])) {
                    $egestorId = (string)$eP['codigo'];
                }
            }

            if ($egestorId) {
                $saleProducts[] = [
                    'codProduto' => (int)$egestorId,
                    'preco' => $price,
                    'quant' => $qty,
                    'vDesc' => $itemDiscount,
                    'obs' => "Pedido Nuvemshop #{$orderId}"
                ];
            }
        }

        // 4. Se já temos a venda registrada no banco local, evita duplicidade
        $stmtCheck = $db->prepare("SELECT egestor_sale_id FROM orders_sync WHERE nuvemshop_order_id = ? LIMIT 1");
        $stmtCheck->execute([$orderId]);
        $existingSaleId = $stmtCheck->fetchColumn();

        if ($existingSaleId) {
            Logger::info("Venda para o pedido Nuvemshop #{$orderId} já foi lançada no eGestor (ID Venda #{$existingSaleId}).");
            return [
                'success' => true,
                'message' => 'Venda já registrada no eGestor',
                'sale_id' => $existingSaleId,
                'contact_id' => $contactId
            ];
        }

        // 5. Se o cliente foi identificado e há produtos mapeados, lança a venda no eGestor
        if ($contactId && !empty($saleProducts)) {
            $freight = (float)($order['shipping_cost_customer'] ?? 0);
            $dtVenda = date('Y-m-d', strtotime($order['created_at'] ?? 'now'));

            $salePayload = [
                'codContato' => $contactId,
                'codVendedor' => 1,
                'dtVenda' => $dtVenda,
                'produtos' => $saleProducts,
                'valorFrete' => $freight,
                'tags' => ['Nuvemshop']
            ];

            $saleRes = $this->egestor->createSale($salePayload);

            if ($saleRes['success'] && (!empty($saleRes['data']['codigo']) || !empty($saleRes['data']['id']))) {
                $saleId = (string)($saleRes['data']['codigo'] ?? $saleRes['data']['id']);
                Logger::info("Venda #{$saleId} lançada no eGestor com sucesso para o pedido #{$orderId}!");

                // Salva histórico no banco local
                $stmtSave = $db->prepare("INSERT INTO orders_sync (nuvemshop_order_id, customer_name, customer_cpf_cnpj, customer_email, customer_phone, egestor_contact_id, egestor_sale_id, order_total, status, details)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sale_registered', ?)
                    ON CONFLICT(nuvemshop_order_id) DO UPDATE SET
                        customer_name = excluded.customer_name,
                        customer_cpf_cnpj = excluded.customer_cpf_cnpj,
                        customer_email = excluded.customer_email,
                        customer_phone = excluded.customer_phone,
                        egestor_contact_id = excluded.egestor_contact_id,
                        egestor_sale_id = excluded.egestor_sale_id,
                        order_total = excluded.order_total,
                        status = 'sale_registered',
                        details = excluded.details,
                        updated_at = CURRENT_TIMESTAMP");
                $stmtSave->execute([
                    $orderId,
                    $customerName,
                    $cleanCpf,
                    $email,
                    $phone,
                    (string)$contactId,
                    $saleId,
                    (float)($order['total'] ?? 0),
                    "Venda #{$saleId} criada no eGestor pronta para emissão de NF-e"
                ]);

                Database::logSync('nuvemshop', 'egestor_sale_created', (string)$orderId, "Venda #{$saleId} lançada no eGestor para cliente '{$customerName}' (CPF {$cleanCpf}). Pronta para emissão de NF-e.", 'success');

                return [
                    'success' => true,
                    'contact_id' => $contactId,
                    'sale_id' => $saleId,
                    'message' => "Venda #{$saleId} registrada no eGestor com sucesso (Pronta para NF-e)"
                ];
            } else {
                $err = $saleRes['error'] ?? 'Falha ao registrar venda no eGestor';
                Logger::error("Erro ao registrar venda no eGestor para pedido #{$orderId}: {$err}", ['body' => $saleRes['data'] ?? []]);
                
                // Registra tentativa no banco local para acompanhamento
                $stmtSave = $db->prepare("INSERT INTO orders_sync (nuvemshop_order_id, customer_name, customer_cpf_cnpj, customer_email, customer_phone, egestor_contact_id, order_total, status, details)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'error', ?)
                    ON CONFLICT(nuvemshop_order_id) DO UPDATE SET
                        status = 'error',
                        details = excluded.details,
                        updated_at = CURRENT_TIMESTAMP");
                $stmtSave->execute([
                    $orderId, $customerName, $cleanCpf, $email, $phone, (string)$contactId, (float)($order['total'] ?? 0), "Erro eGestor: {$err}"
                ]);

                return ['success' => false, 'error' => $err, 'contact_id' => $contactId];
            }
        } else {
            $reason = !$contactId ? 'Cliente não cadastrado no eGestor' : 'Nenhum produto mapeado no pedido';
            Logger::warning("Não foi possível lançar venda no eGestor para pedido #{$orderId}: {$reason}");
            
            $stmtSave = $db->prepare("INSERT INTO orders_sync (nuvemshop_order_id, customer_name, customer_cpf_cnpj, customer_email, customer_phone, egestor_contact_id, order_total, status, details)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)
                ON CONFLICT(nuvemshop_order_id) DO UPDATE SET
                    details = excluded.details,
                    updated_at = CURRENT_TIMESTAMP");
            $stmtSave->execute([
                $orderId, $customerName, $cleanCpf, $email, $phone, (string)$contactId, (float)($order['total'] ?? 0), "Pendente: {$reason}"
            ]);

            return ['success' => false, 'error' => $reason];
        }
    }
}
