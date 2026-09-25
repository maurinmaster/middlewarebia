<?php

namespace BiaMiddleware;

class NuvemshopClient {
    private string $storeId;
    private string $accessToken;
    private string $userAgent;
    private string $locationId;
    private string $baseUrl;

    public function __construct(array $config) {
        $this->storeId     = (string)($config['store_id'] ?? '');
        $this->accessToken = (string)($config['access_token'] ?? '');
        $this->userAgent   = (string)($config['user_agent'] ?? 'lojaBia');
        $this->locationId  = (string)($config['location_id'] ?? '01KZ4G2W4BPEJX3MQ4ARW1B692');
        $this->baseUrl     = rtrim($config['api_url'] ?? 'https://api.nuvemshop.com.br/v1', '/') . '/' . $this->storeId;
    }

    private function request(string $method, string $endpoint, array $data = []): array {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $ch = curl_init();

        $headers = [
            'Authentication: bearer ' . $this->accessToken,
            'User-Agent: ' . $this->userAgent,
            'Content-Type: application/json; charset=utf-8'
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers
        ];

        if (!empty($data) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Logger::error("Erro cURL Nuvemshop [{$method} {$endpoint}]: {$curlError}");
            throw new \Exception("Falha na requisição cURL: {$curlError}");
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $msg = $decoded['message'] ?? $decoded['description'] ?? "HTTP {$httpCode}";
            Logger::warning("Resposta com erro Nuvemshop [{$httpCode}] [{$method} {$endpoint}]: {$msg}", ['body' => $decoded]);
            return [
                'success' => false,
                'status' => $httpCode,
                'error' => $msg,
                'data' => $decoded
            ];
        }

        return [
            'success' => true,
            'status' => $httpCode,
            'data' => $decoded
        ];
    }

    public function testConnection(): array {
        return $this->request('GET', '/store');
    }

    public function getProducts(int $page = 1, int $perPage = 50, array $params = []): array {
        $query = http_build_query(array_merge(['page' => $page, 'per_page' => $perPage], $params));
        return $this->request('GET', '/products?' . $query);
    }

    public function getProduct(int|string $productId): array {
        return $this->request('GET', "/products/{$productId}");
    }

    /**
     * Procura produto/variante na Nuvemshop por SKU
     */
    public function findProductVariantBySku(string $sku): ?array {
        $sku = trim($sku);
        if ($sku === '') return null;

        // Nuvemshop permite filtrar produtos por query / sku ou listar
        $res = $this->request('GET', '/products?q=' . urlencode($sku));
        if ($res['success'] && !empty($res['data'])) {
            foreach ($res['data'] as $product) {
                if (!empty($product['variants'])) {
                    foreach ($product['variants'] as $variant) {
                        if (trim((string)($variant['sku'] ?? '')) === $sku) {
                            return [
                                'product' => $product,
                                'variant' => $variant
                            ];
                        }
                    }
                }
            }
        }

        // Se a busca por 'q' não achar de primeira, varre a primeira página de produtos
        $allRes = $this->getProducts(1, 100);
        if ($allRes['success'] && !empty($allRes['data'])) {
            foreach ($allRes['data'] as $product) {
                if (!empty($product['variants'])) {
                    foreach ($product['variants'] as $variant) {
                        if (trim((string)($variant['sku'] ?? '')) === $sku) {
                            return [
                                'product' => $product,
                                'variant' => $variant
                            ];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Procura produto/variante na Nuvemshop por Código de Barras
     */
    public function findProductVariantByBarcode(string $barcode): ?array {
        $barcode = trim($barcode);
        $upper = strtoupper($barcode);
        if ($barcode === '' || $upper === 'SEM GTIN' || $upper === 'SEM_GTIN' || $barcode === '0') {
            return null;
        }

        $allRes = $this->getProducts(1, 100);
        if ($allRes['success'] && !empty($allRes['data'])) {
            foreach ($allRes['data'] as $product) {
                if (!empty($product['variants'])) {
                    foreach ($product['variants'] as $variant) {
                        if (trim((string)($variant['barcode'] ?? '')) === $barcode) {
                            return [
                                'product' => $product,
                                'variant' => $variant
                            ];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Atualiza estoque da variante na Nuvemshop
     */
    public function updateVariantStock(int|string $productId, int|string $variantId, int $newStock): array {
        $payload = [
            'stock' => $newStock,
            'inventory_levels' => [
                [
                    'location_id' => $this->locationId,
                    'stock' => $newStock
                ]
            ]
        ];

        Logger::info("Atualizando estoque na Nuvemshop: Produto {$productId}, Variante {$variantId}, Novo Estoque: {$newStock}");
        return $this->request('PUT', "/products/{$productId}/variants/{$variantId}", $payload);
    }

    /**
     * Atualiza preço da variante na Nuvemshop
     */
    public function updateVariantPrice(int|string $productId, int|string $variantId, float $price): array {
        $payload = [
            'price' => number_format($price, 2, '.', '')
        ];
        return $this->request('PUT', "/products/{$productId}/variants/{$variantId}", $payload);
    }

    /**
     * Cria um novo produto na Nuvemshop
     */
    public function createProduct(array $data): array {
        $payload = [
            'name' => [
                'pt' => $data['name'] ?? 'Produto Sem Nome'
            ],
            'description' => [
                'pt' => $data['description'] ?? ''
            ],
            'variants' => [
                [
                    'price' => number_format((float)($data['price'] ?? 0), 2, '.', ''),
                    'stock' => (int)($data['stock'] ?? 0),
                    'stock_management' => true,
                    'sku' => (string)($data['sku'] ?? ''),
                    'barcode' => (string)($data['barcode'] ?? ''),
                    'inventory_levels' => [
                        [
                            'location_id' => $this->locationId,
                            'stock' => (int)($data['stock'] ?? 0)
                        ]
                    ]
                ]
            ]
        ];

        if (!empty($data['images'])) {
            $payload['images'] = [];
            foreach ((array)$data['images'] as $imgUrl) {
                if (filter_var($imgUrl, FILTER_VALIDATE_URL)) {
                    $payload['images'][] = ['src' => $imgUrl];
                }
            }
        }

        Logger::info("Criando novo produto na Nuvemshop: " . ($data['name'] ?? ''), ['payload' => $payload]);
        return $this->request('POST', '/products', $payload);
    }

    /**
     * Lista webhooks registrados na Nuvemshop
     */
    public function listWebhooks(): array {
        return $this->request('GET', '/webhooks');
    }

    /**
     * Registra webhook na Nuvemshop
     */
    public function registerWebhook(string $event, string $url): array {
        $payload = [
            'event' => $event,
            'url' => $url
        ];
        return $this->request('POST', '/webhooks', $payload);
    }

    /**
     * Remove webhook na Nuvemshop
     */
    public function deleteWebhook(int|string $webhookId): array {
        return $this->request('DELETE', "/webhooks/{$webhookId}");
    }
}
