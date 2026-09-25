<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/src/Logger.php';
require_once dirname(__DIR__) . '/src/Database.php';

$config = require dirname(__DIR__) . '/config/config.php';

$currentBaseUrl = $config['app_url'];

$egestorWebhookUrl = $currentBaseUrl . '/webhook-egestor.php';
$nuvemshopWebhookUrl = $currentBaseUrl . '/webhook-nuvemshop.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Middleware Bia | Integração PDV eGestor ⇄ Nuvemshop</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="container">
  <!-- Header -->
  <header>
    <div class="brand">
      <div class="logo-badge">⚡</div>
      <div class="brand-text">
        <h1>Middleware Bia &bull; PDV ⇄ Nuvemshop</h1>
        <p>Sincronização bidirecional de catálogo e controle unificado de estoques em tempo real</p>
      </div>
    </div>
    <div class="header-actions">
      <button class="btn btn-secondary" onclick="openTestWebhookModal()">
        🧪 Testar Webhook
      </button>
      <button class="btn btn-primary" onclick="importEGestorToNuvemshop()" style="background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);">
        🚀 Importar eGestor ➔ Nuvemshop
      </button>
      <button class="btn btn-secondary" onclick="openCreateProductModal()">
        ➕ Inserir Produto
      </button>
      <button class="btn btn-secondary" onclick="openSettingsModal()" title="Configurações">
        ⚙️
      </button>
    </div>
  </header>

  <!-- Status Cards -->
  <div class="grid-cards">
    <!-- Nuvemshop -->
    <div class="card">
      <div class="card-title">
        <span>Nuvemshop (Loja Virtual)</span>
        <span id="nuvem-status-badge" class="status-badge warning">
          <span class="status-dot"></span> Verificando...
        </span>
      </div>
      <div class="card-stat" id="nuvem-store-name">Carregando...</div>
      <div class="card-desc">Loja ID: <strong><?= htmlspecialchars($config['nuvemshop']['store_id']) ?></strong></div>
      <div class="endpoint-box">
        <span class="endpoint-url" id="nuvem-url-text"><?= htmlspecialchars($nuvemshopWebhookUrl) ?></span>
        <button class="btn btn-secondary btn-sm" onclick="copyToClipboard('<?= htmlspecialchars($nuvemshopWebhookUrl) ?>', 'URL do webhook Nuvemshop copiada!')">Copiar</button>
      </div>
      <button class="btn btn-secondary btn-sm" style="margin-top: 10px; width: 100%; justify-content: center;" onclick="registerNuvemshopWebhooks()">
        ⚡ Ativar Webhooks na Nuvemshop
      </button>
    </div>

    <!-- eGestor -->
    <div class="card">
      <div class="card-title">
        <span>eGestor (PDV / Caixa)</span>
        <span id="egestor-status-badge" class="status-badge warning">
          <span class="status-dot"></span> Verificando...
        </span>
      </div>
      <div class="card-stat" id="egestor-company-name">Conectando...</div>
      <div class="card-desc">Token: <strong><?= htmlspecialchars(substr($config['egestor']['security_token'], 0, 10)) ?>...</strong></div>
      <div class="endpoint-box">
        <span class="endpoint-url" id="egestor-url-text"><?= htmlspecialchars($egestorWebhookUrl) ?></span>
        <button class="btn btn-secondary btn-sm" onclick="copyToClipboard('<?= htmlspecialchars($egestorWebhookUrl) ?>', 'URL do webhook eGestor copiada!')">Copiar</button>
      </div>
    </div>

    <!-- Estoque & Mapeamentos -->
    <div class="card">
      <div class="card-title">
        <span>Catálogo Mapeado</span>
        <span class="status-badge online"><span class="status-dot"></span> Ativo</span>
      </div>
      <div class="card-stat" id="stat-total-products">0</div>
      <div class="card-desc">Última sincronização: <strong id="stat-last-sync">Nenhuma</strong></div>
      <div style="margin-top: 12px; font-size: 13px; color: var(--text-dim);">
        Erros nas últimas 24h: <strong id="stat-errors" style="color: var(--danger);">0</strong>
      </div>
    </div>
  </div>

  <!-- Tabs Navigation -->
  <div class="tabs">
    <button class="tab-btn active" data-tab="tab-products">📦 Estoque de Produtos</button>
    <button class="tab-btn" data-tab="tab-logs">📜 Logs em Tempo Real</button>
    <button class="tab-btn" data-tab="tab-guide">📖 Como Configurar no eGestor</button>
  </div>

  <!-- Tab 1: Produtos -->
  <div id="tab-products" class="tab-content active">
    <div class="table-container">
      <div class="table-header">
        <div style="font-weight: 600; font-size: 15px;">Produtos Integrados</div>
        <div style="display: flex; gap: 10px; align-items: center;">
          <input type="text" id="search-products" class="search-input" placeholder="🔍 Buscar por SKU, Nome ou Código de Barras...">
          <button class="btn btn-secondary btn-sm" onclick="loadProducts()">Atualizar Lista</button>
        </div>
      </div>
      <table>
        <thead>
          <tr>
            <th>SKU / Código</th>
            <th>Código de Barras</th>
            <th>Descrição do Produto</th>
            <th>Estoque Sincronizado</th>
            <th>Preço</th>
            <th>Status</th>
            <th style="text-align: right;">Ações</th>
          </tr>
        </thead>
        <tbody id="products-table-body">
          <!-- Inserido dinamicamente via app.js -->
        </tbody>
      </table>
    </div>
  </div>

  <!-- Tab 2: Logs -->
  <div id="tab-logs" class="tab-content">
    <div class="table-container" style="padding: 16px;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
        <span style="font-weight: 600;">Monitor de Eventos e Requisições</span>
        <button class="btn btn-secondary btn-sm" onclick="loadLogs()">Limpar / Atualizar</button>
      </div>
      <div class="terminal" id="logs-terminal">
        Carregando logs do sistema...
      </div>
    </div>
  </div>

  <!-- Tab 3: Guia de Instalação -->
  <div id="tab-guide" class="tab-content">
    <div class="card" style="line-height: 1.8;">
      <h2 style="font-size: 18px; margin-bottom: 14px;">📋 Passo a Passo para Ativar no eGestor:</h2>
      <ol style="padding-left: 20px; color: var(--text-muted); font-size: 14px;">
        <li>Acesse seu painel do <strong>eGestor</strong> (<a href="https://v4.egestor.com.br/config/#/configGeral" target="_blank" style="color: var(--primary);">Configurações &gt; Webhooks</a>).</li>
        <li>No campo <strong>Endereço (endpoint)</strong>, cole a URL deste servidor:
          <div class="endpoint-box" style="margin: 8px 0 14px 0;">
            <span class="endpoint-url"><?= htmlspecialchars($egestorWebhookUrl) ?></span>
            <button class="btn btn-secondary btn-sm" onclick="copyToClipboard('<?= htmlspecialchars($egestorWebhookUrl) ?>')">Copiar</button>
          </div>
        </li>
        <li>Confirme que o <strong>Security Token</strong> é: <code style="color: #93c5fd;"><?= htmlspecialchars($config['egestor']['security_token']) ?></code></li>
        <li>Marque a opção <strong>[X] Enviar como JSON</strong>.</li>
        <li>Em <strong>Webhooks ativos</strong>, mantenha marcados:
          <strong style="color: #fff;">[x] Produtos</strong> e <strong style="color: #fff;">[x] Vendas</strong>.
        </li>
        <li>Clique no botão verde <strong>Salvar</strong>.</li>
      </ol>

      <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border-color);">
        <h3 style="font-size: 15px; margin-bottom: 8px; color: #fff;">🔑 Como obter o Personal Token do eGestor:</h3>
        <p style="color: var(--text-muted); font-size: 13px;">
          O eGestor requer um <strong>Personal Token</strong> para que o middleware consiga ler os detalhes de cada produto (nome, preço, estoque atual) quando o webhook dispara.
          Acesse: <strong>Configurações &gt; API</strong> no menu do eGestor, gere seu token pessoal e clique na engrenagem ⚙️ no topo deste painel para salvá-lo.
        </p>
      </div>
    </div>
  </div>
</div>

<!-- Modal Configurações -->
<div id="settings-modal" class="modal-overlay">
  <div class="modal">
    <div class="modal-header">
      <h3>Configurações do Middleware</h3>
      <button class="modal-close" onclick="closeSettingsModal()">&times;</button>
    </div>
    <form onsubmit="saveSettings(event)">
      <div class="form-group">
        <label>Personal Token do eGestor (Menu Configurações &gt; API):</label>
        <textarea id="input-personal-token" class="form-control" rows="4" placeholder="Cole o token JWT gerado no eGestor aqui..."></textarea>
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
        <button type="button" class="btn btn-secondary" onclick="closeSettingsModal()">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar Token</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Inserir Produto Nuvemshop -->
<div id="create-product-modal" class="modal-overlay">
  <div class="modal">
    <div class="modal-header">
      <h3>Inserir Novo Produto na Nuvemshop</h3>
      <button class="modal-close" onclick="closeCreateProductModal()">&times;</button>
    </div>
    <form onsubmit="submitCreateProduct(event)">
      <div class="form-group">
        <label>Nome do Produto *:</label>
        <input type="text" name="name" class="form-control" required placeholder="Ex: Vestido Estampado Bia">
      </div>
      <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
        <div>
          <label>SKU (Código PDV):</label>
          <input type="text" name="sku" class="form-control" placeholder="Ex: 2180">
        </div>
        <div>
          <label>Código de Barras (EAN):</label>
          <input type="text" name="barcode" class="form-control" placeholder="Ex: 789123456789">
        </div>
      </div>
      <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
        <div>
          <label>Preço de Venda (R$):</label>
          <input type="number" step="0.01" name="price" class="form-control" required placeholder="149.90">
        </div>
        <div>
          <label>Estoque Inicial:</label>
          <input type="number" name="stock" class="form-control" required placeholder="5">
        </div>
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
        <button type="button" class="btn btn-secondary" onclick="closeCreateProductModal()">Cancelar</button>
        <button type="submit" class="btn btn-primary">Cadastrar Produto</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Testar Webhook -->
<div id="test-webhook-modal" class="modal-overlay">
  <div class="modal">
    <div class="modal-header">
      <h3>Simular Webhook do eGestor</h3>
      <button class="modal-close" onclick="closeTestWebhookModal()">&times;</button>
    </div>
    <form onsubmit="submitTestWebhook(event)">
      <div class="form-group">
        <label>Código do Produto no eGestor (ID):</label>
        <input type="text" id="test-webhook-codigo" class="form-control" required placeholder="Ex: 1">
      </div>
      <p style="font-size: 12px; color: var(--text-dim); margin-bottom: 16px;">
        Isto enviará uma requisição simulada exatamente como o eGestor envia quando um produto tem o estoque ou preço alterado no PDV.
      </p>
      <div style="display: flex; justify-content: flex-end; gap: 10px;">
        <button type="button" class="btn btn-secondary" onclick="closeTestWebhookModal()">Cancelar</button>
        <button type="submit" class="btn btn-primary">Disparar Teste</button>
      </div>
    </form>
  </div>
</div>

<div class="toast-container" id="toast-container"></div>

<script src="assets/js/app.js"></script>
</body>
</html>
