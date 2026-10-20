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
            if (!empty($decoded['errFields'])) {
                if (is_array($decoded['errFields'])) {
                    $fieldsStr = json_encode($decoded['errFields'], JSON_UNESCAPED_UNICODE);
                    $msg .= " (Campos: {$fieldsStr})";
                } else {
                    $msg .= " (Campos: {$decoded['errFields']})";
                }
            }
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
     * Cria um novo produto no eGestor (PDV)
     */
    public function createProduct(array $data): array {
        $payload = [
            'descricao' => trim((string)($data['descricao'] ?? $data['name'] ?? 'Produto')),
            'precoVenda' => (float)($data['precoVenda'] ?? $data['price'] ?? 0),
            'estoque' => (float)($data['estoque'] ?? $data['stock'] ?? 0),
            'controlarEstoque' => 1
        ];

        if (!empty($data['codigoProprio']) || !empty($data['sku'])) {
            $payload['codigoProprio'] = trim((string)($data['codigoProprio'] ?? $data['sku']));
        }

        if (!empty($data['precoCusto'])) {
            $payload['precoCusto'] = (float)$data['precoCusto'];
        }

        if (!empty($data['refEanGtin']) || !empty($data['barcode'])) {
            $payload['refEanGtin'] = trim((string)($data['refEanGtin'] ?? $data['barcode']));
        }

        if (!empty($data['anotacoesInternas'])) {
            $payload['anotacoesInternas'] = trim((string)$data['anotacoesInternas']);
        }

        // Dados Fiscais para NF-e no eGestor
        if (!empty($data['ncm'])) {
            $payload['ncm'] = preg_replace('/\D/', '', (string)$data['ncm']);
        }
        if (!empty($data['codigoCEST']) || !empty($data['cest'])) {
            $payload['codigoCEST'] = preg_replace('/\D/', '', (string)($data['codigoCEST'] ?? $data['cest']));
        }
        if (isset($data['origemFiscal']) && $data['origemFiscal'] !== '') {
            $payload['origemFiscal'] = (int)$data['origemFiscal'];
        }
        if (!empty($data['unidadeTributada'])) {
            $payload['unidadeTributada'] = strtoupper(trim((string)$data['unidadeTributada']));
        }
        if (!empty($data['codigoGrupoTributos']) || !empty($data['grupoTributos'])) {
            $payload['codigoGrupoTributos'] = trim((string)($data['codigoGrupoTributos'] ?? $data['grupoTributos']));
        }
        if (!empty($data['codigoBeneficioFiscal']) || !empty($data['beneficioFiscal'])) {
            $payload['codigoBeneficioFiscal'] = trim((string)($data['codigoBeneficioFiscal'] ?? $data['beneficioFiscal']));
        }
        if (!empty($data['excecaoIPI'])) {
            $payload['excecaoIPI'] = trim((string)$data['excecaoIPI']);
        }
        if (!empty($data['anotacoesNFE']) || !empty($data['anotacoesNfe'])) {
            $payload['anotacoesNFE'] = trim((string)($data['anotacoesNFE'] ?? $data['anotacoesNfe']));
        }
        if (isset($data['pesoLiquido']) && $data['pesoLiquido'] !== '') {
            $payload['pesoLiquido'] = (float)$data['pesoLiquido'];
        }
        if (isset($data['pesoBruto']) && $data['pesoBruto'] !== '') {
            $payload['pesoBruto'] = (float)$data['pesoBruto'];
        }

        Logger::info("Criando produto no eGestor: '{$payload['descricao']}'", ['payload' => $payload]);
        return $this->request('POST', '/produtos', $payload);
    }

    /**
     * Exclui um produto no eGestor
     */
    public function deleteProduct(int|string $codigo): array {
        Logger::info("Excluindo produto {$codigo} no eGestor...");
        return $this->request('DELETE', "/produtos/{$codigo}");
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

    /**
     * Busca contato no eGestor por CPF ou CNPJ
     */
    public function findContactByCpf(string $cpfCnpj): ?array {
        $clean = preg_replace('/\D/', '', $cpfCnpj);
        if (empty($clean)) return null;

        $res = $this->request('GET', '/contatos?filtro=' . urlencode($clean));
        if ($res['success'] && !empty($res['data']['data'])) {
            foreach ($res['data']['data'] as $c) {
                $contactCpf = preg_replace('/\D/', '', (string)($c['cpfcnpj'] ?? ''));
                if ($contactCpf === $clean) {
                    return $c;
                }
            }
            // Se o filtro retornou registros, verifica o primeiro
            $first = $res['data']['data'][0] ?? null;
            if ($first && preg_replace('/\D/', '', (string)($first['cpfcnpj'] ?? '')) === $clean) {
                return $first;
            }
        }
        return null;
    }

    /**
     * Busca contato no eGestor por Nome exato ou aproximado
     */
    public function findContactByName(string $name): ?array {
        $name = trim($name);
        if ($name === '') return null;

        $res = $this->request('GET', '/contatos?filtro=' . urlencode($name));
        if ($res['success'] && !empty($res['data']['data'])) {
            foreach ($res['data']['data'] as $c) {
                if (strcasecmp(trim($c['nome'] ?? ''), $name) === 0) {
                    return $c;
                }
            }
            return $res['data']['data'][0] ?? null;
        }
        return null;
    }

    /**
     * Cria um novo contato/cliente no eGestor com dados fiscais completos para NF-e
     */
    public function createContact(array $data): array {
        $cleanCpf = preg_replace('/\D/', '', (string)($data['cpfcnpj'] ?? $data['cpf_cnpj'] ?? $data['identification'] ?? ''));
        $nome = trim((string)($data['nome'] ?? $data['name'] ?? 'Cliente Nuvemshop'));

        $emails = [];
        if (!empty($data['email'])) {
            $emails[] = trim((string)$data['email']);
        } elseif (!empty($data['emails']) && is_array($data['emails'])) {
            $emails = array_map('trim', $data['emails']);
        }

        $fones = [];
        $rawPhone = $data['phone'] ?? $data['telefone'] ?? $data['celular'] ?? null;
        if (!empty($rawPhone)) {
            $cleanPhone = preg_replace('/\D/', '', (string)$rawPhone);
            if ($cleanPhone !== '') $fones[] = $cleanPhone;
        } elseif (!empty($data['fones']) && is_array($data['fones'])) {
            $fones = $data['fones'];
        }

        $cep = preg_replace('/\D/', '', (string)($data['cep'] ?? $data['zipcode'] ?? ''));
        $logradouro = trim((string)($data['logradouro'] ?? $data['endereco'] ?? $data['address'] ?? ''));
        $numero = trim((string)($data['numero'] ?? $data['number'] ?? 'S/N'));
        $complemento = trim((string)($data['complemento'] ?? $data['floor'] ?? ''));
        $bairro = trim((string)($data['bairro'] ?? $data['locality'] ?? ''));
        $uf = strtoupper(trim((string)($data['uf'] ?? $data['state'] ?? '')));

        $payload = [
            'nome' => $nome,
            'tipo' => ['cliente'],
            'cpfcnpj' => $cleanCpf,
            'clienteFinal' => true,
            'indicadorIE' => 9, // 9 = Não Contribuinte ICMS
            'emails' => $emails,
            'fones' => $fones,
            'logradouro' => $logradouro,
            'numero' => $numero,
            'complemento' => $complemento,
            'bairro' => $bairro,
            // NOTA: 'cidade' não pode ser enviado para a API do eGestor em POST /contatos pois é preenchido automaticamente via CEP e gera 'Campo cidade é somente para leitura.'
            'uf' => $uf,
            'tags' => ['Nuvemshop'],
            'obs' => trim((string)($data['obs'] ?? 'Cadastrado automaticamente via Nuvemshop'))
        ];

        if (!empty($cep) && (int)$cep > 0) {
            $payload['cep'] = (int)$cep;
        }

        if (!empty($data['fantasia'])) {
            $payload['fantasia'] = trim((string)$data['fantasia']);
        }

        Logger::info("Criando contato no eGestor para cliente '{$nome}' (CPF/CNPJ: {$cleanCpf})...", ['payload' => $payload]);
        return $this->request('POST', '/contatos', $payload);
    }

    /**
     * Atualiza dados de um contato no eGestor
     */
    public function updateContact(int|string $codContato, array $data): array {
        Logger::info("Atualizando contato {$codContato} no eGestor...", $data);
        return $this->request('PUT', "/contatos/{$codContato}", $data);
    }

    /**
     * Registra uma Venda de produtos no eGestor (preparada para emissão de NF-e)
     */
    public function createSale(array $data): array {
        $rawProdutos = $data['produtos'] ?? $data['itens'] ?? [];
        if (empty($rawProdutos)) {
            return ['success' => false, 'error' => 'Nenhum produto informado para a venda'];
        }

        $globalObs = trim((string)($data['obs'] ?? ''));
        $globalDiscount = (float)($data['valorDesconto'] ?? 0);
        $totalItems = count($rawProdutos);
        $itemDiscountFallback = ($totalItems > 0 && $globalDiscount > 0) ? round($globalDiscount / $totalItems, 2) : 0;

        $produtos = [];
        foreach ($rawProdutos as $p) {
            $codProduto = (int)($p['codProduto'] ?? $p['codigo'] ?? $p['id'] ?? 0);
            if ($codProduto <= 0) continue;

            $item = [
                'codProduto' => $codProduto,
                'preco' => (float)($p['preco'] ?? $p['price'] ?? 0),
                'quant' => (float)($p['quant'] ?? $p['quantity'] ?? 1),
                'vDesc' => (float)($p['vDesc'] ?? $itemDiscountFallback)
            ];

            $itemObs = trim((string)($p['obs'] ?? $globalObs));
            if ($itemObs !== '') {
                $item['obs'] = $itemObs;
            }

            $produtos[] = $item;
        }

        if (empty($produtos)) {
            return ['success' => false, 'error' => 'Nenhum produto válido mapeado para envio ao eGestor'];
        }

        // A API v1 do eGestor exige 'codVendedor' e NÃO aceita 'obs' ou 'valorDesconto' na raiz da venda
        $payload = [
            'codContato' => (int)($data['codContato'] ?? 0),
            'codVendedor' => (int)($data['codVendedor'] ?? 1),
            'dtVenda' => $data['dtVenda'] ?? date('Y-m-d'),
            'produtos' => $produtos,
            'valorFrete' => (float)($data['valorFrete'] ?? 0),
            'tags' => ['Nuvemshop']
        ];

        Logger::info("Lançando Venda no eGestor para contato ID {$payload['codContato']} com " . count($produtos) . " item(ns)...", ['payload' => $payload]);
        return $this->request('POST', '/vendas', $payload);
    }

    /**
     * Exclui um contato no eGestor
     */
    public function deleteContact(int|string $codContato): array {
        Logger::info("Excluindo contato {$codContato} no eGestor...");
        return $this->request('DELETE', "/contatos/{$codContato}");
    }

    /**
     * Consulta detalhes de uma venda no eGestor
     */
    public function getSale(int|string $codVenda): array {
        return $this->request('GET', "/vendas/{$codVenda}");
    }

    /**
     * Exclui uma venda no eGestor
     */
    public function deleteSale(int|string $codVenda): array {
        Logger::info("Excluindo venda {$codVenda} no eGestor...");
        return $this->request('DELETE', "/vendas/{$codVenda}");
    }

    /**
     * Consulta detalhes de uma compra no eGestor
     */
    public function getPurchase(int|string $codCompra): array {
        return $this->request('GET', "/compras/{$codCompra}");
    }

    /**
     * Lista compras do eGestor
     */
    public function getPurchases(int $page = 1, array $params = []): array {
        $query = http_build_query(array_merge(['page' => $page], $params));
        return $this->request('GET', '/compras?' . $query);
    }
}
