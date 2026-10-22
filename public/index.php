<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/src/Logger.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Auth.php';

use BiaMiddleware\Auth;

Auth::requireAuth();
$currentUser = Auth::user();

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
  <title>Middleware Bia | Lista de Produtos e Gestão PDV ⇄ Nuvemshop</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>

<div class="app-container">

  <!-- ========================================================
       SIDEBAR LATERAL COM BOTÕES E CADASTRO EM EVIDÊNCIA
       ======================================================== -->
  <aside class="app-sidebar">
    <!-- Brand -->
    <div class="sidebar-brand">
      <div class="brand-icon">⚡</div>
      <div>
        <div class="brand-title">Bia Middleware</div>
        <div class="brand-subtitle">PDV eGestor ⇄ Nuvemshop</div>
      </div>
    </div>

    <!-- Botão de Cadastrar Produto em Alta Evidência (CTA Principal) -->
    <div class="sidebar-cta-wrap">
      <button class="btn-sidebar-cta" onclick="switchToNewProductTab()" id="btn-sidebar-new-prod">
        <span style="font-size: 17px;">➕</span>
        <span>Cadastrar Produto</span>
      </button>
    </div>

    <!-- Menu de Navegação -->
    <div class="sidebar-nav-title">Menu Principal</div>
    <nav class="sidebar-nav">
      <button class="nav-link-btn active" data-tab="tab-lista-produtos">
        <span class="nav-icon">📦</span>
        <span>Lista de Produtos</span>
      </button>
      <button class="nav-link-btn" data-tab="tab-pedidos">
        <span class="nav-icon">🧾</span>
        <span>Vendas &amp; NF-e</span>
      </button>
      <button class="nav-link-btn" data-tab="tab-novo-produto">
        <span class="nav-icon">➕</span>
        <span>Novo Produto</span>
      </button>
      <button class="nav-link-btn" data-tab="tab-logs">
        <span class="nav-icon">📜</span>
        <span>Logs do Sistema</span>
      </button>
      <button class="nav-link-btn" data-tab="tab-config">
        <span class="nav-icon">⚙️</span>
        <span>Configurações &amp; Webhooks</span>
      </button>
      <button class="nav-link-btn" data-tab="tab-guide">
        <span class="nav-icon">📖</span>
        <span>Guia do eGestor</span>
      </button>
    </nav>

    <!-- Rodapé: Usuário e Status de Conexão das APIs -->
    <div class="sidebar-footer">
      <div class="user-profile-card">
        <div class="user-profile-info">
          <div class="user-avatar">👤</div>
          <div class="user-meta">
            <div class="user-name"><?= htmlspecialchars($currentUser['name'] ?? 'Administrador') ?></div>
            <div class="user-role">@<?= htmlspecialchars($currentUser['username'] ?? 'admin') ?></div>
          </div>
        </div>
        <a href="logout.php" class="btn-logout" title="Sair da sessão">
          <span>Sair</span>
          <span>🚪</span>
        </a>
      </div>

      <div class="conn-pill">
        <div class="conn-pill-header">
          <span class="conn-pill-name">Nuvemshop</span>
          <span id="nuvem-status-badge" class="status-badge warning">
            <span class="status-dot"></span> Verificando...
          </span>
        </div>
        <div class="conn-pill-sub" id="nuvem-store-name">Loja ID <?= htmlspecialchars($config['nuvemshop']['store_id']) ?></div>
      </div>

      <div class="conn-pill">
        <div class="conn-pill-header">
          <span class="conn-pill-name">eGestor PDV</span>
          <span id="egestor-status-badge" class="status-badge warning">
            <span class="status-dot"></span> Verificando...
          </span>
        </div>
        <div class="conn-pill-sub" id="egestor-company-name">Conectando...</div>
      </div>

      <div style="font-size: 11px; color: var(--text-dim); text-align: center; margin-top: 4px;">
        ⚡ Sincronização automática ativa
      </div>
    </div>
  </aside>

  <!-- ========================================================
       ÁREA DE CONTEÚDO PRINCIPAL
       ======================================================== -->
  <main class="app-main">

    <!-- Topbar Informativa -->
    <header class="main-header">
      <div class="main-header-title">
        <h1 id="page-current-title">Lista de Produtos</h1>
        <p id="page-current-desc">Catálogo do eGestor integrado à Nuvemshop com controle unificado de estoques</p>
      </div>

      <div class="topbar-stats">
        <div class="stat-chip" title="Total de itens cadastrados no eGestor">
          <span>📦 Total no eGestor:</span>
          <strong id="topbar-total-egestor">...</strong>
        </div>
        <div class="stat-chip" title="Total de produtos com sincronização ativa na Nuvemshop">
          <span>☁️ Na Nuvemshop:</span>
          <strong id="topbar-total-nuvem">0</strong>
        </div>
        <div class="stat-chip" title="Status da última sincronização automática">
          <span>🔄 Sync:</span>
          <strong id="topbar-last-sync" style="color: var(--success);">Ao carregar</strong>
        </div>
        <button class="btn btn-secondary btn-sm" onclick="refreshCurrentView()" title="Recarregar dados atuais">
          🔄 Atualizar
        </button>
      </div>
    </header>

    <!-- ========================================================
         ABA 1: LISTA DE PRODUTOS (CONSOLIDADA: EGESTOR VS NUVEMSHOP)
         ======================================================== -->
    <section id="tab-lista-produtos" class="tab-content active">
      <div class="table-container">
        
        <!-- Barra de Filtros -->
        <div class="table-header-bar">
          <div class="filter-group">
            <input type="text" id="search-products-list" class="search-input" placeholder="🔍 Buscar por Código, SKU, Barras ou Nome..." onkeydown="if(event.key==='Enter') loadProductsList(1)">
            
            <select id="filter-status-nuvem" class="select-filter" onchange="loadProductsList(1)">
              <option value="all" selected>⚡ Todos os Produtos do eGestor</option>
              <option value="in_nuvem">✅ Já na Nuvemshop</option>
              <option value="missing">⚠️ Não enviados para a Nuvem</option>
            </select>

            <select id="filter-stock-level" class="select-filter" onchange="loadProductsList(1)">
              <option value="all">📦 Todo Estoque</option>
              <option value="has_stock">✅ Com Estoque (&gt; 0)</option>
              <option value="zero_stock">🛑 Sem Estoque (= 0)</option>
              <option value="low_stock">⚠️ Estoque Baixo (1 a 3)</option>
            </select>

            <button class="btn btn-secondary btn-sm" onclick="resetProductsListFilters()">Limpar</button>
            <button class="btn btn-primary btn-sm" onclick="loadProductsList(1)">Buscar</button>
          </div>

          <div style="display: flex; gap: 8px; align-items: center;">
            <button class="btn btn-secondary btn-sm" id="btn-open-unify-modal" onclick="openUnifyModal()" style="border-color: #6366f1; color: #6366f1; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;" disabled title="Selecione 2 ou mais produtos com as caixas de seleção da tabela para agrupar em um único anúncio com variações">
              🔗 Unificar em Grade (<span id="unify-selected-count">0</span>)
            </button>
            <button class="btn btn-excel btn-sm" onclick="openExportModal()" id="btn-export-excel" title="Extrair dados para planilha Excel (.xlsx)">
              📊 Extrair para Excel
            </button>
            <button class="btn btn-primary btn-sm" onclick="switchToNewProductTab()" style="background: #2563eb;">
              ➕ Cadastrar Novo
            </button>
          </div>
        </div>

        <!-- Sub-barra com Contador e Paginação Topo -->
        <div class="table-subbar">
          <span id="products-list-summary">Carregando catálogo do eGestor...</span>
          <div id="pagination-top-btns" class="pagination-buttons"></div>
        </div>

        <!-- Tabela Consolidada -->
        <div class="table-responsive">
          <table>
            <thead>
              <tr>
                <th style="width: 44px; text-align: center;">
                  <input type="checkbox" id="select-all-unify" onchange="toggleSelectAllUnify(this)" title="Selecionar todos desta página para unificar">
                </th>
                <th style="width: 100px;">Cód. eGestor</th>
                <th style="width: 110px;">SKU</th>
                <th style="width: 130px;">Cód. Barras</th>
                <th>Descrição do Produto</th>
                <th style="width: 120px;">Estoque PDV</th>
                <th style="width: 120px;">Estoque Nuvem</th>
                <th style="width: 110px;">Preço</th>
                <th style="width: 170px;">Status Nuvemshop</th>
                <th style="text-align: right; width: 170px;">Ações</th>
              </tr>
            </thead>
            <tbody id="products-list-tbody">
              <tr>
                <td colspan="10" style="text-align: center; color: var(--text-dim); padding: 30px;">
                  Carregando lista de produtos...
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Rodapé de Paginação -->
        <div class="table-pagination-footer">
          <span id="pagination-bottom-info">Página 1</span>
          <div id="pagination-bottom-btns" class="pagination-buttons"></div>
        </div>

      </div>
    </section>

    <!-- ========================================================
         ABA: VENDAS & NF-E (NUVEMSHOP ⇄ EGESTOR)
         ======================================================== -->
    <section id="tab-pedidos" class="tab-content">
      <div class="table-container">
        
        <div class="table-header-bar">
          <div>
            <h3 style="font-size: 15px; font-weight: 700; color: var(--text-main);">🧾 Vendas da Nuvemshop &amp; Emissão no eGestor</h3>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
              Pedidos recebidos na Nuvemshop com clientes cadastrados e vendas prontas para emissão de NF-e no eGestor (Opção A).
            </p>
          </div>

          <div style="display: flex; gap: 8px; align-items: center;">
            <button class="btn btn-secondary btn-sm" onclick="loadOrdersList()" title="Atualizar listagem de pedidos">
              🔄 Atualizar Pedidos
            </button>
            <button class="btn btn-primary btn-sm" onclick="openManualOrderSyncModal()" title="Importar pedido específico da Nuvemshop manualmente">
              ➕ Importar Pedido
            </button>
          </div>
        </div>

        <!-- Tabela de Pedidos -->
        <div class="table-responsive">
          <table>
            <thead>
              <tr>
                <th style="width: 140px;">Pedido Nuvem #</th>
                <th>Cliente</th>
                <th style="width: 140px;">CPF / CNPJ</th>
                <th style="width: 120px;">Total (R$)</th>
                <th style="width: 110px;">ID Contato</th>
                <th style="width: 110px;">ID Venda</th>
                <th style="width: 220px;">Status no eGestor</th>
                <th style="width: 150px;">Data / Hora</th>
                <th style="text-align: right; width: 140px;">Ações</th>
              </tr>
            </thead>
            <tbody id="orders-list-tbody">
              <tr>
                <td colspan="9" style="text-align: center; color: var(--text-dim); padding: 30px;">
                  Carregando pedidos sincronizados...
                </td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>
    </section>

    <!-- ========================================================
         ABA 2: CADASTRAR PRODUTO (NOS MOLDES DA NUVEMSHOP)
         ======================================================== -->
    <section id="tab-novo-produto" class="tab-content">
      <form id="form-create-full-product" onsubmit="submitFullProduct(event)">
        <div class="product-create-container">
          
          <!-- Coluna Principal (Esquerda) -->
          <div class="product-create-main">

            <!-- Card 1: Nome e Descrição -->
            <div class="product-form-card">
              <div class="product-form-card-header">
                <div class="product-form-card-title">📝 Nome e Descrição</div>
                <div class="product-form-card-desc">Informações principais que aparecem na loja virtual e no PDV físico</div>
              </div>
              <div class="form-group">
                <label for="prod-name">Nome do Produto *</label>
                <input type="text" id="prod-name" class="form-control" required placeholder="Ex: Vestido Longo Bia Flores" style="font-size: 15px; padding: 12px 14px;">
                <small style="color: var(--text-dim); font-size: 12px; margin-top: 4px; display: block;">
                  O nome principal será usado na Nuvemshop e servirá de base para o nome de cada variação no eGestor.
                </small>
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label for="prod-description">Descrição Completa (Opcional)</label>
                <textarea id="prod-description" class="form-control" rows="4" placeholder="Descreva os detalhes da peça, tecido, caimento e recomendações..."></textarea>
              </div>
            </div>

            <!-- Card 2: Preço e Custos -->
            <div class="product-form-card">
              <div class="product-form-card-header">
                <div class="product-form-card-title">💰 Preços e Custos</div>
                <div class="product-form-card-desc">Defina o preço de venda para os clientes e o custo para sua gestão financeira</div>
              </div>
              <div class="form-grid-2">
                <div class="form-group">
                  <label for="prod-price">Preço de Venda (R$) *</label>
                  <input type="number" step="0.01" min="0" id="prod-price" class="form-control" required placeholder="149.90" oninput="onBasePriceChange()">
                  <small style="color: var(--text-dim); font-size: 11px;">Preço exibido na Nuvemshop e no eGestor</small>
                </div>
                <div class="form-group">
                  <label for="prod-cost-price">Preço de Custo (R$)</label>
                  <input type="number" step="0.01" min="0" id="prod-cost-price" class="form-control" placeholder="65.00">
                  <small style="color: var(--text-dim); font-size: 11px;">Gravado no eGestor para cálculo de lucro</small>
                </div>
              </div>
            </div>

            <!-- Card 3: Variações (Cores e Tamanhos/Modelos) -->
            <div class="product-form-card">
              <div class="product-form-card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <div>
                  <div class="product-form-card-title">🎨 Variações (Cores e Tamanhos)</div>
                  <div class="product-form-card-desc">Crie seletores de cor e tamanho na Nuvemshop mantendo cada item separado no eGestor</div>
                </div>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--text-main); background: #f8fafc; padding: 6px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                  <input type="checkbox" id="chk-has-variants" onchange="toggleHasVariants(this.checked)" checked style="width: 16px; height: 16px; cursor: pointer;">
                  <span>Este produto possui variações</span>
                </label>
              </div>

              <!-- Bloco de Produto Simples (quando NÃO tiver variações) -->
              <div id="simple-product-block" style="display: none;">
                <div class="form-grid-3">
                  <div class="form-group">
                    <label for="prod-simple-stock">Estoque Inicial *</label>
                    <input type="number" step="1" min="0" id="prod-simple-stock" class="form-control" value="1" placeholder="Ex: 10">
                  </div>
                  <div class="form-group">
                    <label for="prod-simple-sku">SKU / Código Interno</label>
                    <input type="text" id="prod-simple-sku" class="form-control" placeholder="Ex: VEST-01">
                    <small style="color: var(--text-dim); font-size: 11px;">Se vazio, usará o código do eGestor</small>
                  </div>
                  <div class="form-group">
                    <label for="prod-simple-barcode">Código de Barras (EAN)</label>
                    <input type="text" id="prod-simple-barcode" class="form-control" placeholder="Ex: 789123456789">
                  </div>
                </div>
              </div>

              <!-- Bloco de Grade / Variações (quando TIVER variações) -->
              <div id="variants-product-block">
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 14px;">
                  Digite as opções de cada propriedade separadas por <strong>vírgula</strong>. As combinações serão geradas automaticamente abaixo:
                </p>

                <!-- Propriedade 1: Cor -->
                <div class="variant-prop-box">
                  <div class="form-grid-2">
                    <div class="form-group" style="margin-bottom: 0;">
                      <label>Nome da Propriedade 1:</label>
                      <input type="text" id="prop-1-name" class="form-control" value="Cor" placeholder="Ex: Cor" oninput="generateVariantsTable()">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                      <label>Valores (separados por vírgula):</label>
                      <input type="text" id="prop-1-values" class="form-control" value="Verde, Rosa, Preto" placeholder="Ex: Verde, Rosa, Preto" oninput="generateVariantsTable()">
                    </div>
                  </div>
                </div>

                <!-- Propriedade 2: Tamanho ou Modelo -->
                <div class="variant-prop-box" id="prop-2-box">
                  <div class="form-grid-2">
                    <div class="form-group" style="margin-bottom: 0;">
                      <label>Nome da Propriedade 2:</label>
                      <input type="text" id="prop-2-name" class="form-control" value="Tamanho" placeholder="Ex: Tamanho ou Modelo" oninput="generateVariantsTable()">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                      <label>Valores (separados por vírgula):</label>
                      <input type="text" id="prop-2-values" class="form-control" value="P, M, G" placeholder="Ex: P, M, G ou Curto, Longo" oninput="generateVariantsTable()">
                    </div>
                  </div>
                </div>

                <!-- Alternar 2ª Propriedade -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin: 10px 0 14px 0;">
                  <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted); cursor: pointer;">
                    <input type="checkbox" id="chk-enable-prop-2" checked onchange="toggleProp2(this.checked)">
                    <span>Incluir 2ª Propriedade (Tamanho/Modelo)</span>
                  </label>
                  <button type="button" class="btn btn-secondary btn-sm" onclick="generateVariantsTable()">🔄 Recalcular Combinações</button>
                </div>

                <!-- Tabela de Combinações Geradas -->
                <div>
                  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 600; font-size: 13px; color: var(--text-main);">
                      Combinações Geradas (<span id="variants-count-badge" style="color: var(--primary); font-weight: 700;">0</span> variações):
                    </label>
                    <span style="font-size: 11px; color: var(--text-dim);">Ajuste estoque e preços individuais se necessário</span>
                  </div>
                  <div class="variant-table-wrap">
                    <table>
                      <thead>
                        <tr>
                          <th>Variação</th>
                          <th style="width: 120px;">Preço (R$)</th>
                          <th style="width: 100px;">Estoque *</th>
                          <th style="width: 140px;">SKU (Opcional)</th>
                          <th style="width: 140px;">Cód. Barras</th>
                        </tr>
                      </thead>
                      <tbody id="variants-tbody">
                        <!-- Preenchido dinamicamente via app.js -->
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

            <!-- Card 4: Dados Fiscais (NF-e & eGestor) -->
            <div class="product-form-card">
              <div class="product-form-card-header">
                <div class="product-form-card-title">🏛️ Dados Fiscais (NF-e & eGestor)</div>
                <div class="product-form-card-desc">Parâmetros tributários e fiscais cadastrados diretamente no eGestor para emissão automática de NF-e</div>
              </div>
              <div class="form-grid-3">
                <div class="form-group">
                  <label for="prod-ncm">NCM *</label>
                  <input type="text" id="prod-ncm" class="form-control" placeholder="Ex: 61069000 ou 6106.90.00" maxlength="12">
                  <small style="color: var(--text-dim); font-size: 11px;">8 dígitos (obrigatório para emissão de NF-e)</small>
                </div>
                <div class="form-group">
                  <label for="prod-cest">CEST</label>
                  <input type="text" id="prod-cest" class="form-control" placeholder="Ex: 0100100" maxlength="10">
                  <small style="color: var(--text-dim); font-size: 11px;">Para produtos com Substituição Tributária</small>
                </div>
                <div class="form-group">
                  <label for="prod-origem-fiscal">Origem da Mercadoria</label>
                  <select id="prod-origem-fiscal" class="form-control">
                    <option value="0" selected>0 - Nacional</option>
                    <option value="1">1 - Estrangeira (Importação direta)</option>
                    <option value="2">2 - Estrangeira (Adquirida no mercado interno)</option>
                    <option value="3">3 - Nacional (Conteúdo importação > 40%)</option>
                    <option value="4">4 - Nacional (Produção conforme processos básicos)</option>
                    <option value="5">5 - Nacional (Conteúdo importação &le; 40%)</option>
                    <option value="6">6 - Estrangeira (Importação direta s/ similar)</option>
                    <option value="7">7 - Estrangeira (Mercado interno s/ similar)</option>
                    <option value="8">8 - Nacional (Conteúdo importação > 70%)</option>
                  </select>
                  <small style="color: var(--text-dim); font-size: 11px;">Origem fiscal da mercadoria</small>
                </div>
              </div>

              <div class="form-grid-3" style="margin-top: 10px;">
                <div class="form-group">
                  <label for="prod-unidade-tributada">Unidade Tributada</label>
                  <select id="prod-unidade-tributada" class="form-control">
                    <option value="UN" selected>UN - Unidade</option>
                    <option value="PC">PC - Peça</option>
                    <option value="PAR">PAR - Par</option>
                    <option value="KG">KG - Quilograma</option>
                    <option value="MT">MT - Metro</option>
                    <option value="CX">CX - Caixa</option>
                    <option value="KIT">KIT - Conjunto / Kit</option>
                  </select>
                  <small style="color: var(--text-dim); font-size: 11px;">Unidade na nota fiscal</small>
                </div>
                <div class="form-group">
                  <label for="prod-grupo-tributos">Cód. Regra/Grupo Tributos</label>
                  <input type="text" id="prod-grupo-tributos" class="form-control" value="1" placeholder="Ex: 1">
                  <small style="color: var(--text-dim); font-size: 11px;">ID do Grupo de Tributos no eGestor</small>
                </div>
                <div class="form-group">
                  <label for="prod-beneficio-fiscal">Benefício Fiscal (cBenef)</label>
                  <input type="text" id="prod-beneficio-fiscal" class="form-control" placeholder="Ex: RS051000 (se houver)">
                  <small style="color: var(--text-dim); font-size: 11px;">Código benefício estadual</small>
                </div>
              </div>

              <div class="form-grid-2" style="margin-top: 10px;">
                <div class="form-group">
                  <label for="prod-peso-liquido">Peso Líquido (kg)</label>
                  <input type="number" step="0.001" min="0" id="prod-peso-liquido" class="form-control" placeholder="0.250">
                  <small style="color: var(--text-dim); font-size: 11px;">Peso do item sem embalagem</small>
                </div>
                <div class="form-group">
                  <label for="prod-peso-bruto">Peso Bruto (kg)</label>
                  <input type="number" step="0.001" min="0" id="prod-peso-bruto" class="form-control" placeholder="0.300">
                  <small style="color: var(--text-dim); font-size: 11px;">Peso com embalagem para cálculo de frete/NF</small>
                </div>
              </div>

              <div class="form-group" style="margin-bottom: 0; margin-top: 10px;">
                <label for="prod-anotacoes-nfe">Anotações Adicionais para a NF-e (Opcional)</label>
                <input type="text" id="prod-anotacoes-nfe" class="form-control" placeholder="Ex: Inf. Adicionais do Produto impressas na nota fiscal...">
              </div>
            </div>

            <!-- Card 5: Fotos (Opcional) -->
            <div class="product-form-card">
              <div class="product-form-card-header">
                <div class="product-form-card-title">🖼️ Fotos do Produto (Opcional)</div>
                <div class="product-form-card-desc">Cole links diretos de imagens na web (uma URL por linha)</div>
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <textarea id="prod-images" class="form-control" rows="3" placeholder="https://exemplo.com/foto1.jpg&#10;https://exemplo.com/foto2.jpg"></textarea>
              </div>
            </div>

          </div>

          <!-- Coluna Lateral (Direita): Publicação -->
          <div class="product-create-sidebar">
            <div class="product-form-card" style="position: sticky; top: 20px;">
              <div class="product-form-card-header">
                <div class="product-form-card-title">🚀 Publicação</div>
              </div>

              <div style="margin-bottom: 16px;">
                <button type="submit" id="btn-submit-product" class="btn-primary-action">
                  💾 Salvar e Cadastrar Produto
                </button>
              </div>

              <div id="create-product-status-box" style="display: none; padding: 12px; border-radius: var(--radius-sm); font-size: 12px; margin-bottom: 16px; background: #f8fafc; border: 1px solid var(--border-color); line-height: 1.5;">
                <div id="create-product-status-text">Processando...</div>
              </div>

              <div class="info-sidebar-box">
                <h4>🛡️ Como o Middleware processa:</h4>
                <ul>
                  <li><strong>eGestor:</strong> Cria cada variação como um produto separado no seu PDV com estoque independente.</li>
                  <li><strong>Nuvemshop:</strong> Cria um produto unificado com a grade completa (Cor e Tamanho) em uma única chamada.</li>
                  <li><strong>Baixa Precisa:</strong> Vendas na Nuvemshop dão baixa no item exato do eGestor.</li>
                </ul>
              </div>
            </div>
          </div>

        </div>
      </form>
    </section>

    <!-- ========================================================
         ABA 3: LOGS DO SISTEMA
         ======================================================== -->
    <section id="tab-logs" class="tab-content">
      <div class="table-container" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
          <div>
            <h3 style="font-size: 16px; font-weight: 700;">Monitor de Eventos e Requisições em Tempo Real</h3>
            <p style="font-size: 12px; color: var(--text-muted);">Registro de webhooks, baixas de estoque de vendas e sincronizações</p>
          </div>
          <button class="btn btn-secondary btn-sm" onclick="loadLogs()">🔄 Atualizar Logs</button>
        </div>
        <div class="terminal" id="logs-terminal">
          Carregando registros do sistema...
        </div>
      </div>
    </section>

    <!-- ========================================================
         ABA 4: CONFIGURAÇÕES E WEBHOOKS
         ======================================================== -->
    <section id="tab-config" class="tab-content">
      <div class="product-form-card" style="max-width: 800px;">
        <div class="product-form-card-header">
          <div class="product-form-card-title">⚙️ Configurações Gerais &amp; Webhooks</div>
          <div class="product-form-card-desc">Parâmetros de conexão e chaves de integração</div>
        </div>

        <form onsubmit="saveSettings(event)">
          <div class="form-group">
            <label for="input-personal-token">Personal Token do eGestor (Menu Configurações &gt; API):</label>
            <textarea id="input-personal-token" class="form-control" rows="3" placeholder="Cole o token JWT gerado no eGestor aqui..."></textarea>
            <small style="color: var(--text-dim); font-size: 12px; margin-top: 4px; display: block;">
              Necessário para que o middleware consulte e salve itens automaticamente no eGestor.
            </small>
          </div>

          <div class="form-group" style="padding: 14px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); margin-top: 16px;">
            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin-bottom: 0;">
              <input type="checkbox" id="input-sync-only-stock" checked style="margin-top: 3px; width: 16px; height: 16px; cursor: pointer;">
              <div>
                <strong style="color: var(--text-main); font-size: 13px;">Regra Padrão: Enviar apenas produtos com Estoque &gt; 0 para a Nuvemshop</strong>
                <p style="font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.4;">
                  Quando ativado, novos produtos com estoque 0 no eGestor não serão enviados automaticamente para a Nuvemshop. Se o produto já existir na loja, o estoque continuará sendo atualizado normalmente.
                </p>
              </div>
            </label>
          </div>

          <div style="margin-top: 20px;">
            <button type="submit" class="btn btn-primary">Salvar Configurações</button>
          </div>
        </form>

        <hr style="border: none; border-top: 1px solid var(--border-color); margin: 28px 0;">

        <!-- Endpoints de Webhook -->
        <h4 style="font-size: 14px; font-weight: 700; margin-bottom: 12px;">🔗 URLs de Webhook para Configuração</h4>
        
        <div class="form-group">
          <label>Webhook eGestor (Insira nas configurações de Webhooks do eGestor):</label>
          <div class="endpoint-box">
            <span class="endpoint-url" id="egestor-url-text"><?= htmlspecialchars($egestorWebhookUrl) ?></span>
            <button class="btn btn-secondary btn-sm" onclick="copyToClipboard('<?= htmlspecialchars($egestorWebhookUrl) ?>', 'URL do eGestor copiada!')">Copiar</button>
          </div>
        </div>

        <div class="form-group">
          <label>Webhook Nuvemshop (Eventos de pedidos e estoque):</label>
          <div class="endpoint-box">
            <span class="endpoint-url" id="nuvem-url-text"><?= htmlspecialchars($nuvemshopWebhookUrl) ?></span>
            <button class="btn btn-secondary btn-sm" onclick="copyToClipboard('<?= htmlspecialchars($nuvemshopWebhookUrl) ?>', 'URL da Nuvemshop copiada!')">Copiar</button>
          </div>
          <button class="btn btn-secondary btn-sm" style="margin-top: 10px;" onclick="registerNuvemshopWebhooks()">
            ⚡ Ativar Webhooks na Nuvemshop Automaticamente
          </button>
        </div>

        <hr style="border: none; border-top: 1px solid var(--border-color); margin: 28px 0;">

        <!-- Segurança e Alteração de Senha -->
        <h4 style="font-size: 14px; font-weight: 700; margin-bottom: 8px;">🔒 Segurança de Acesso ao Sistema</h4>
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
          Altere a senha de acesso da conta de administrador (<strong>@<?= htmlspecialchars($currentUser['username'] ?? 'admin') ?></strong>).
        </p>

        <form id="form-change-password" onsubmit="submitChangePassword(event)" style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 18px;">
          <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
            <div class="form-group" style="margin-bottom: 0;">
              <label for="pwd-current">Senha Atual *</label>
              <input type="password" id="pwd-current" class="form-control" placeholder="••••••••" required autocomplete="current-password">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label for="pwd-new">Nova Senha *</label>
              <input type="password" id="pwd-new" class="form-control" placeholder="Mínimo 4 caracteres" required minlength="4" autocomplete="new-password">
            </div>
          </div>
          <div style="display: flex; justify-content: flex-end;">
            <button type="submit" id="btn-save-password" class="btn btn-secondary">
              🔒 Salvar Nova Senha
            </button>
          </div>
        </form>
      </div>
    </section>

    <!-- ========================================================
         ABA 5: GUIA DO EGESTOR
         ======================================================== -->
    <section id="tab-guide" class="tab-content">
      <div class="product-form-card" style="max-width: 800px; line-height: 1.8;">
        <h2 style="font-size: 18px; font-weight: 700; margin-bottom: 14px;">📋 Passo a Passo para Ativar no eGestor:</h2>
        <ol style="padding-left: 20px; color: var(--text-muted); font-size: 14px;">
          <li>Acesse seu painel do <strong>eGestor</strong> (<a href="https://v4.egestor.com.br/config/#/configGeral" target="_blank" style="color: var(--primary);">Configurações &gt; Webhooks</a>).</li>
          <li>No campo <strong>Endereço (endpoint)</strong>, cole a URL deste servidor:
            <div class="endpoint-box" style="margin: 8px 0 14px 0;">
              <span class="endpoint-url"><?= htmlspecialchars($egestorWebhookUrl) ?></span>
              <button class="btn btn-secondary btn-sm" onclick="copyToClipboard('<?= htmlspecialchars($egestorWebhookUrl) ?>')">Copiar</button>
            </div>
          </li>
          <li>Confirme que o <strong>Security Token</strong> é: <code style="color: var(--primary); font-weight: 600;"><?= htmlspecialchars($config['egestor']['security_token']) ?></code></li>
          <li>Marque a opção <strong>[X] Enviar como JSON</strong>.</li>
          <li>Em <strong>Webhooks ativos</strong>, mantenha marcados:
            <strong style="color: var(--text-main);">[x] Produtos</strong> e <strong style="color: var(--text-main);">[x] Vendas</strong>.
          </li>
          <li>Clique no botão verde <strong>Salvar</strong>.</li>
        </ol>

        <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border-color);">
          <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 8px; color: var(--text-main);">🔑 Como obter o Personal Token do eGestor:</h3>
          <p style="color: var(--text-muted); font-size: 13px;">
            O eGestor requer um <strong>Personal Token</strong> para que o middleware consiga ler os detalhes de cada produto (nome, preço, estoque atual) quando o webhook dispara.
            Acesse: <strong>Configurações &gt; API</strong> no menu do eGestor, gere seu token pessoal e cole na aba <strong>Configurações &amp; Webhooks</strong> ao lado.
          </p>
        </div>
      </div>
    </section>

  </main>

</div>

<!-- Modal Envio Manual de Produto -->
<div id="manual-send-modal" class="modal-overlay">
  <div class="modal" style="max-width: 500px;">
    <div class="modal-header">
      <h3>📤 Envio Manual de Produto para a Nuvemshop</h3>
      <button class="modal-close" onclick="closeManualSendModal()">&times;</button>
    </div>
    <form onsubmit="submitManualSend(event)">
      <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
        Informe o <strong>Código (ID)</strong> do produto no eGestor para sincronizá-lo diretamente com a Nuvemshop.
      </p>
      <div class="form-group">
        <label>Código do Produto no eGestor (ID) *:</label>
        <input type="text" id="manual-send-codigo" class="form-control" required placeholder="Ex: 2180">
      </div>
      <div class="form-group" style="padding: 12px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
        <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 13px; color: var(--text-main); margin-bottom: 0;">
          <input type="checkbox" id="manual-send-force" checked style="margin-top: 3px;">
          <div>
            <strong>Forçar envio mesmo com estoque zerado</strong>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
              Se marcado, enviará o produto para a Nuvemshop mesmo se a regra padrão estiver ativa.
            </p>
          </div>
        </label>
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
        <button type="button" class="btn btn-secondary" onclick="closeManualSendModal()">Cancelar</button>
        <button type="submit" class="btn btn-primary" id="btn-manual-send-submit">📤 Enviar Agora</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal de Exportação para Excel -->
<div id="export-excel-modal" class="modal-overlay">
  <div class="modal" style="max-width: 520px;">
    <div class="modal-header">
      <h3>📊 Extrair Dados para Excel</h3>
      <button class="modal-close" onclick="closeExportModal()">&times;</button>
    </div>
    
    <div style="margin-bottom: 20px;">
      <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 14px; line-height: 1.5;">
        Escolha quais produtos deseja exportar para a planilha do Microsoft Excel (<strong>.xlsx</strong>):
      </p>

      <div style="display: flex; flex-direction: column; gap: 10px;">
        <label class="export-option-label" style="display: flex; align-items: flex-start; gap: 10px; padding: 12px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); cursor: pointer;">
          <input type="radio" name="export-scope" value="visible" checked style="margin-top: 3px; cursor: pointer;">
          <div>
            <strong style="font-size: 13px; color: var(--text-main);">Produtos da Página Atual</strong>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 2px;" id="export-visible-desc">
              Exporta os produtos atualmente visíveis na tabela com os filtros aplicados.
            </p>
          </div>
        </label>

        <label class="export-option-label" style="display: flex; align-items: flex-start; gap: 10px; padding: 12px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); cursor: pointer;">
          <input type="radio" name="export-scope" value="egestor_api" style="margin-top: 3px; cursor: pointer;">
          <div>
            <strong style="font-size: 13px; color: var(--text-main);">Catálogo Completo do eGestor (via API)</strong>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
              Consulta e extrai todos os produtos cadastrados no eGestor PDV diretamente pela API, indicando quais já estão na Nuvemshop.
            </p>
          </div>
        </label>
      </div>

      <div style="margin-top: 14px; padding: 12px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: var(--radius-sm); display: flex; align-items: center; gap: 10px; font-size: 12px; color: #065f46; line-height: 1.4;">
        <span style="font-size: 18px;">📊</span>
        <span>A planilha gerada inclui <strong>dados fiscais completos (NCM, CEST, Origem Fiscal, GTIN Tributável, Unidade, Preço de Custo, Tributos e Pesos)</strong> e estoques PDV ⇄ Nuvemshop formatados em <strong>.xlsx nativo</strong>.</span>
      </div>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 10px;">
      <button type="button" class="btn btn-secondary" onclick="closeExportModal()">Cancelar</button>
      <button type="button" class="btn btn-excel" id="btn-do-export-excel" onclick="executeExcelExport()">
        📥 Baixar Planilha Excel (.xlsx)
      </button>
    </div>
  </div>
</div>

<!-- Modal Importação Manual de Pedido Nuvemshop -->
<div id="manual-order-modal" class="modal-overlay">
  <div class="modal" style="max-width: 480px;">
    <div class="modal-header">
      <h3>🧾 Importar Pedido para o eGestor (NF-e)</h3>
      <button class="modal-close" onclick="closeManualOrderSyncModal()">&times;</button>
    </div>
    <form onsubmit="submitManualOrderSync(event)">
      <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 14px; line-height: 1.5;">
        Informe o <strong>ID do Pedido</strong> na Nuvemshop para cadastrar o cliente (com CPF/CNPJ e endereço) e lançar a Venda no eGestor pronta para emissão de NF-e.
      </p>
      <div class="form-group">
        <label for="manual-order-id">ID do Pedido na Nuvemshop *:</label>
        <input type="text" id="manual-order-id" class="form-control" required placeholder="Ex: 123456789">
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px;">
        <button type="button" class="btn btn-secondary" onclick="closeManualOrderSyncModal()">Cancelar</button>
        <button type="submit" class="btn btn-primary" id="btn-manual-order-submit">🚀 Enviar para eGestor</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Unificar Produtos do eGestor em Grade na Nuvemshop -->
<div id="unify-modal" class="modal-overlay">
  <div class="modal" style="max-width: 920px; max-height: 90vh; display: flex; flex-direction: column;">
    <div class="modal-header">
      <div>
        <h3>🔗 Unificar Produtos em Grade na Nuvemshop</h3>
        <p style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">
          Agrupa os produtos individuais selecionados do eGestor em um único produto com variações de Cor e Tamanho na Nuvemshop.
        </p>
      </div>
      <button class="modal-close" onclick="closeUnifyModal()">&times;</button>
    </div>
    
    <div style="overflow-y: auto; padding-right: 6px; flex: 1;">
      <!-- Dados Principais do Anúncio -->
      <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 14px;">
        <div class="form-group" style="margin-bottom: 0;">
          <label>Nome Principal na Nuvemshop *:</label>
          <input type="text" id="unify-product-name" class="form-control" required placeholder="Ex: Conjunto de Calça 6000">
          <small style="color: var(--text-muted); font-size: 11px;">Nome comum da vitrine que reunirá todas as cores.</small>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
          <label>Preço Padrão (R$):</label>
          <input type="number" step="0.01" id="unify-default-price" class="form-control" placeholder="Opcional" oninput="applyDefaultPriceToAll(this.value)">
          <small style="color: var(--text-muted); font-size: 11px;">Se digitado, ajusta o preço de todas as variações.</small>
        </div>
      </div>

      <div class="form-group" style="margin-bottom: 14px;">
        <label>Descrição do Produto:</label>
        <textarea id="unify-product-desc" class="form-control" rows="2" placeholder="Descrição do produto para o e-commerce..."></textarea>
      </div>

      <!-- Configuração dos Atributos da Grade -->
      <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 14px; margin-bottom: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
          <strong style="font-size: 13px; color: var(--text-main);">Propriedades da Grade (Atributos)</strong>
          <label style="font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; cursor: pointer;">
            <input type="checkbox" id="unify-enable-attr2" onchange="toggleUnifyAttr2(this.checked)" checked>
            Incluir 2º Atributo (ex: Tamanho)
          </label>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
          <div>
            <label style="font-size: 12px; font-weight: 600;">1º Atributo (ex: Cor):</label>
            <input type="text" id="unify-attr1-name" class="form-control" value="Cor" style="height: 34px;" oninput="updateModalAttrHeaders()">
          </div>
          <div id="unify-attr2-group">
            <label style="font-size: 12px; font-weight: 600;">2º Atributo (ex: Tamanho):</label>
            <div style="display: flex; gap: 8px;">
              <input type="text" id="unify-attr2-name" class="form-control" value="Tamanho" style="height: 34px; width: 45%;" oninput="updateModalAttrHeaders()">
              <input type="text" id="unify-attr2-default" class="form-control" placeholder="Padrão (ex: Único)" value="Único (P/M/G)" style="height: 34px; flex: 1;" title="Digite para aplicar a todas as linhas" oninput="applyDefaultAttr2ToAll(this.value)">
            </div>
          </div>
        </div>
      </div>

      <!-- Tabela com os Itens Selecionados do eGestor -->
      <div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
          <strong style="font-size: 13px; color: var(--text-main);">Produtos do eGestor Vinculados (<span id="unify-modal-items-count">0</span>):</strong>
          <span style="font-size: 11px; color: #4f46e5; font-weight: 500;">💡 O SKU de cada variante amarra a sincronização automática de estoque!</span>
        </div>

        <div class="table-responsive" style="max-height: 260px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
          <table style="font-size: 12px; margin-bottom: 0;">
            <thead style="position: sticky; top: 0; background: #f1f5f9; z-index: 2;">
              <tr>
                <th style="width: 70px;">Cód.</th>
                <th>Nome no eGestor</th>
                <th style="width: 140px;" id="th-unify-attr1">Cor *</th>
                <th style="width: 140px;" id="th-unify-attr2">Tamanho *</th>
                <th style="width: 80px;">Estoque</th>
                <th style="width: 110px;">Preço (R$)</th>
                <th style="width: 100px;">SKU Nuvem</th>
                <th style="width: 36px; text-align: center;"></th>
              </tr>
            </thead>
            <tbody id="unify-modal-tbody">
              <!-- Inserido dinamicamente via JS -->
            </tbody>
          </table>
        </div>
      </div>

      <!-- Status Box -->
      <div id="unify-status-box" style="display: none; margin-top: 14px; padding: 12px; border-radius: var(--radius-sm); font-size: 13px;">
      </div>

    </div>

    <div class="modal-footer" style="margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
      <span style="font-size: 12px; color: var(--text-muted);" id="unify-footer-info"></span>
      <div style="display: flex; gap: 10px;">
        <button type="button" class="btn btn-secondary" onclick="closeUnifyModal()">Cancelar</button>
        <button type="button" class="btn btn-primary" id="btn-submit-unify" onclick="submitUnifyProducts()" style="background: #4f46e5; border-color: #4f46e5;">
          🚀 Criar Produto com Grade na Nuvemshop
        </button>
      </div>
    </div>
  </div>
</div>

<div class="toast-container" id="toast-container"></div>

<script src="assets/js/xlsx.full.min.js?v=<?= filemtime(__DIR__ . '/assets/js/xlsx.full.min.js') ?>"></script>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
