# Middleware Bia &bull; Integração PDV eGestor ⇄ Nuvemshop

Middleware profissional em PHP para sincronização bidirecional em tempo real entre o **eGestor (PDV / Caixa)** e a **Nuvemshop (Loja Virtual)**.

Mantém os estoques sempre iguais e sincronizados:
- Quando uma venda é feita no balcão físico do PDV ou um produto é alterado no eGestor &rarr; o estoque na Nuvemshop é atualizado na hora.
- Se o produto ainda não existir na Nuvemshop, o middleware pode criá-lo automaticamente com nome, SKU, preço e estoque.
- Quando uma venda acontece na loja online (Nuvemshop) &rarr; o estoque no PDV (eGestor) é reduzido para não vender peças duplicadas no balcão.

---

## 🚀 Como Iniciar o Middleware

### 1. Início Rápido (Windows)
Basta dar dois cliques no arquivo:
```bat
start-server.bat
```
Ou pelo terminal:
```powershell
C:\xampp\php\php.exe -S 0.0.0.0:8080 -t public
```
Acesse o painel moderno no navegador:
👉 **[http://localhost:8080](http://localhost:8080)**

---

## ⚙️ Configuração no Painel do eGestor

Conforme a tela de **Configurar webhooks** do seu eGestor:

1. **Endereço (endpoint):**
   - Se estiver hospedado em servidor/VPS com SSL:
     ```
     https://seu-dominio.com.br/webhook-egestor.php
     ```
   - Se estiver rodando no computador local com túnel (ex: Cloudflare Tunnel ou ngrok):
     ```
     https://seu-tunnel.ngrok-free.app/webhook-egestor.php
     ```
2. **Security token:**
   - Mantenha o token já gerado: `4d2bf5aa7a3d610b8ce40bd504037f` (já configurado no seu `.env`).
3. **Enviar como JSON:**
   - Marque a caixa **[x] Enviar como JSON**.
4. **Webhooks ativos:**
   - [x] **Produtos**
   - [x] **Vendas**
5. Clique em **Salvar**.

---

## 🔑 Obter o Personal Token no eGestor (Obrigatório para consultar os produtos)

Quando o webhook do eGestor dispara, ele envia apenas o ID do produto alterado. Para que o middleware possa buscar o nome, SKU, código de barras e o estoque atual na API do eGestor, é necessário o **Personal Token**:

1. No menu lateral do eGestor, acesse: **Configurações > API**.
2. Clique em **Gerar Personal Token** (ou copie o token existente).
3. Cole o token no arquivo [`.env`](file:///c:/Users/mauol/projetos/middlewareBia/.env) no campo:
   ```env
   EGESTOR_PERSONAL_TOKEN=seu_token_jwt_aqui
   ```
   *(Ou abra o painel web em `http://localhost:8080`, clique na engrenagem ⚙️ no canto superior direito e cole lá)*.

---

## 🌐 Configuração na Nuvemshop (Baixa de Estoque por Vendas Online)

Para que as vendas efetuadas na Nuvemshop baixem o estoque no seu PDV eGestor automaticamente:

- **URL do Webhook da Nuvemshop:**
  ```
  https://seu-dominio.com.br/webhook-nuvemshop.php
  ```
- **Eventos:** `order/created` ou `order/paid`.

---

## 💻 Comandos CLI (Terminal)

Você também pode gerenciar o middleware via terminal:

```powershell
# Testar conexão com a Nuvemshop
C:\xampp\php\php.exe sync-cli.php test-nuvem

# Testar conexão com o eGestor
C:\xampp\php\php.exe sync-cli.php test-egestor

# Importar todo o catálogo da Nuvemshop para a base de mapeamento
C:\xampp\php\php.exe sync-cli.php import-nuvem

# Forçar sincronização de estoque de um produto específico
C:\xampp\php\php.exe sync-cli.php sync-prod 123
```

---

## 📁 Estrutura do Projeto

```
middlewareBia/
├── config/
│   └── config.php            # Carregamento de variáveis de ambiente e credenciais
├── src/
│   ├── Database.php          # Gerenciamento SQLite de mapeamentos (SKU/ID) e logs
│   ├── EGestorClient.php      # Cliente da API REST e OAuth do eGestor
│   ├── NuvemshopClient.php   # Cliente da API Nuvemshop (produtos, estoques, variantes)
│   ├── SyncService.php       # Regras de sincronização bidirecional de estoques
│   └── Logger.php            # Registro detalhado de logs em arquivo e banco
├── public/
│   ├── index.php             # Dashboard Web moderno
│   ├── api.php               # API interna do painel
│   ├── webhook-egestor.php   # Endpoint receptor dos webhooks do eGestor
│   ├── webhook-nuvemshop.php # Endpoint receptor dos webhooks da Nuvemshop
│   └── assets/               # CSS estilizado moderno e JavaScript
├── data/
│   ├── database.sqlite       # Banco de dados local SQLite
│   └── logs/                 # Arquivos de log diários
├── tests/
│   └── test_webhook.php      # Script de teste de webhook
├── .env                      # Arquivo de credenciais ativo
├── .env.example              # Exemplo de configuração
├── sync-cli.php              # Ferramenta de linha de comando
└── start-server.bat          # Inicializador rápido no Windows
```
