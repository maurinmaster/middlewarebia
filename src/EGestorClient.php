<?php

namespace BiaMiddleware;

class EGestorClient {
    private string $personalToken;
    private string $securityToken;
    private string $authUrl;
    private string $apiUrl;

    public function __construct(array $config) {
        $this->securityToken = (string)($config['security_token'] ?? '');
        $this->personalToken = (string)($config['personal_token'] ?? '');
        $this->authUrl       = $config['auth_url'] ?? 'https://api.egestor.com.br/api/oauth/access_token';
        $this->apiUrl        = rtrim($config['api_url'] ?? 'https://api.egestor.com.br/api/v1', '/');

        // Se houver personal_token salvo no banco pelo painel, prioriza-o
        $dbToken = Database::getSetting('egestor_personal_token');
        if (!empty($dbToken)) {
            $this->personalToken = $dbToken;
        }
    }

    public function setPersonalToken(string $token): void {
        $this->personalToken = trim($token);
        Database::setSetting('egestor_personal_token', $this->personalToken);
    }

    public function getPersonalToken(): string {
        return $this->personalToken;
    }

    public function getSecurityToken(): string {
        return $this->securityToken;
    }

    /**
     * Valida o token de segurança enviado no Webhook do eGestor
     */
    public function validateWebhookToken(?string $incomingToken): bool {
        if (empty($incomingToken) || empty($this->securityToken)) {
            return false;
        }
        return hash_equals(trim($this->securityToken), trim($incomingToken));
    }

    /**
     * Obtém ou renova o access_token OAuth2
     */
    public function getAccessToken(): string {
        if (empty($this->personalToken)) {
            throw new \Exception('Personal Token do eGestor não configurado. Adicione no arquivo .env ou no painel do middleware.');
        }

        $cachedToken = Database::getSetting('egestor_access_token');
        $expiresAt   = (int)Database::getSetting('egestor_token_expires_at', '0');

        // Se ainda for válido com margem de segurança de 60 segundos
        if (!empty($cachedToken) && $expiresAt > (time() + 60)) {
            return $cachedToken;
        }

        // Renova o access_token
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->authUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'grant_type' => 'personal',
                'personal_token' => $this->personalToken
            ])
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Logger::error("Erro ao autenticar com eGestor: {$curlError}");
            throw new \Exception("Erro de conexão com eGestor: {$curlError}");
        }

        $data = json_decode($response, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            $msg = $data['errMsg'] ?? $data['error'] ?? 'Código ' . $httpCode;
            Logger::error("Falha ao obter access_token do eGestor: {$msg}", ['body' => $data]);
            throw new \Exception("Falha na autenticação com eGestor: {$msg}");
        }

        $accessToken = $data['access_token'];
        $expiresIn = (int)($data['expires_in'] ?? 900);
        $newExpiry = time() + $expiresIn;

        Database::setSetting('egestor_access_token', $accessToken);
        Database::setSetting('egestor_token_expires_at', (string)$newExpiry);

        Logger::info('Novo access_token do eGestor gerado e armazenado com sucesso.');
        return $accessToken;
    }

    /**
     * Faz requisição autenticada para a API v1 do eGestor
     */
    private function request(string $method, string $endpoint, array $data = []): array {
        $accessToken = $this->getAccessToken();
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');

        $ch = curl_init();
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json'
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
            Logger::error("Erro cURL eGestor [{$method} {$endpoint}]: {$curlError}");
            throw new \Exception("Falha na conexão cURL com eGestor: {$curlError}");
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $msg = $decoded['errMsg'] ?? $decoded['message'] ?? "HTTP {$httpCode}";
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

    /**
     * Testa a conexão buscando dados da empresa
     */
    public function testConnection(): array {
        if (empty($this->personalToken)) {
            return [
                'success' => false,
                'status' => 0,
                'error' => 'Personal Token não preenchido. Obtenha no menu Configurações > API do eGestor.'
            ];
        }
        try {
            return $this->request('GET', '/empresa');
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Busca detalhes de um produto pelo código no eGestor
     */
    public function getProduct(int|string $codigo): array {
        return $this->request('GET', "/produtos/{$codigo}");
    }

    /**
     * Lista produtos do eGestor com paginação
     */
    public function getProducts(int $page = 1, array $params = []): array {
        $query = http_build_query(array_merge(['page' => $page], $params));
        return $this->request('GET', '/produtos?' . $query);
    }

    /**
     * Atualiza estoque de um produto no eGestor
     */
    public function updateProductStock(int|string $codigo, float $newStock): array {
        Logger::info("Atualizando estoque no eGestor: Código {$codigo}, Novo Estoque: {$newStock}");
        return $this->request('PUT', "/produtos/{$codigo}", [
            'estoque' => $newStock
        ]);
    }

    /**
     * Busca produto no eGestor por SKU (codigoProprio) ou código
     */
    public function findProductBySku(string $sku): ?array {
        $sku = trim($sku);
        if ($sku === '') return null;

        $res = $this->request('GET', '/produtos?filtro=' . urlencode($sku));
        if ($res['success'] && !empty($res['data']['data'])) {
            foreach ($res['data']['data'] as $p) {
                $codigoProprio = trim((string)($p['codigoProprio'] ?? ''));
                $codigo = trim((string)($p['codigo'] ?? ''));
                if ($codigoProprio === $sku || $codigo === $sku) {
                    return $p;
                }
            }
            return $res['data']['data'][0];
        }
        return null;
    }
}
