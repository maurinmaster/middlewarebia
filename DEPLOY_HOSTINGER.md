# 🚀 Guia de Deploy na Hostinger

O sistema foi preparado especificamente para funcionar na **Hostinger** (hPanel, cPanel, Cloud ou VPS) sem a necessidade de instalar dependências via terminal (zero Composer, PHP nativo e SQLite).

---

### 📋 Checklist de Compatibilidade

- [x] **PHP 8.1, 8.2 ou 8.3** (Padrão da Hostinger)
- [x] **Extensões nativas** (`pdo_sqlite`, `curl`, `json`, `mbstring`, `openssl`)
- [x] **Segurança com `.htaccess`** configurado para proteger arquivos sensíveis (`.env`, `data/database.sqlite`, logs)
- [x] **URL limpa e amigável** (acesso direto a `/webhook-egestor.php` sem precisar digitar `/public/`)
- [x] **SSL Gratuito (HTTPS)** ativado com 1 clique na Hostinger

---

### 🛠️ Passo a Passo para Fazer o Upload

#### Opção 1: Via Gerenciador de Arquivos do hPanel (Mais Rápido)

1. **Compactar os arquivos:**
   - Selecione todos os arquivos da pasta `middlewareBia` (incluindo as pastas `config`, `data`, `public`, `src`, os arquivos `.env`, `.htaccess` e `index.php`).
   - Compacte em um arquivo `.zip` (ex: `middleware.zip`).

2. **Acessar o hPanel da Hostinger:**
   - Entre no painel da Hostinger &rarr; **Websites** &rarr; clique em **Gerenciar** no seu domínio.
   - Abra o **Gerenciador de Arquivos** (File Manager).

3. **Fazer o Upload:**
   - Se for o domínio principal: acesse a pasta `public_html/`.
   - Se for um subdomínio (ex: `api.seudominio.com.br`): acesse a pasta do subdomínio.
   - Clique no ícone de **Enviar (Upload)** no canto superior direito e envie o `middleware.zip`.
   - Clique com o botão direito no `.zip` enviado e escolha **Extrair (Extract)**.

4. **Configurar o `.env`:**
   - No gerenciador de arquivos, abra o arquivo `.env`.
   - Altere a linha `APP_URL` para o seu domínio real:
     ```env
     APP_URL=https://seudominio.com.br
     ```
   - Preencha o `EGESTOR_PERSONAL_TOKEN` com o token gerado no eGestor.
   - Salve o arquivo.

5. **Permissões de Escrita da pasta `data/`:**
   - Clique com botão direito na pasta `data/` &rarr; **Permissões (Permissions)**.
   - Certifique-se de que esteja como `755` ou `775` (leitura e gravação permitidas para o servidor criar o banco SQLite e os logs).

---

#### Opção 2: Via Git (Deploy Automático no hPanel)

Caso você utilize Git no hPanel:
1. No hPanel, vá em **Avançado &gt; Git**.
2. Cole o link do seu repositório Git.
3. Configure a branch principal e clique em **Criar**.
4. Crie o arquivo `.env` diretamente no servidor com suas chaves.

---

### 🔒 3. Ativar o SSL (HTTPS)

Webhooks da Nuvemshop e do eGestor exigem conexão segura HTTPS:
1. No hPanel da Hostinger, procure por **Segurança &gt; SSL**.
2. Verifique se o status está **Ativo**. Caso não esteja, clique em **Instalar SSL Gratuito**.

---

### 🎯 4. URLs Prontas para Cadastro

Após o upload, suas URLs estarão imediatamente disponíveis:

- **Painel Dashboard:**
  `https://seudominio.com.br/`
- **Webhook para o eGestor:**
  `https://seudominio.com.br/webhook-egestor.php`
- **Webhook para a Nuvemshop:**
  `https://seudominio.com.br/webhook-nuvemshop.php`

---

### ❓ Teste Rápido pós-deploy

Abra no navegador: `https://seudominio.com.br/api.php?action=status`
A resposta esperada é um JSON com:
```json
{
  "success": true,
  "nuvemshop": { "connected": true, "store_name": "Loja Bia" },
  "egestor": { "connected": true, ... }
}
```
Se aparecer essa resposta, seu middleware está 100% operacional na nuvem!
