document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    loadStatus();
    loadProducts();
    loadLogs();
    initNewProductForm();

    // Auto-refresh status e logs a cada 30 segundos
    setInterval(() => {
        loadStatus(true);
        if (document.getElementById('tab-logs').classList.contains('active')) {
            loadLogs();
        }
    }, 30000);

    // Eventos de busca
    const searchInput = document.getElementById('search-products');
    if (searchInput) {
        let debounceTimer;
        searchInput.addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                loadProducts(e.target.value);
            }, 300);
        });
    }
});

function initTabs() {
    const tabs = document.querySelectorAll('.tab-btn');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            tab.classList.add('active');
            const targetId = tab.getAttribute('data-tab');
            const targetContent = document.getElementById(targetId);
            if (targetContent) {
                targetContent.classList.add('active');
            }

            if (targetId === 'tab-logs') {
                loadLogs();
            }
        });
    });
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    let icon = 'ℹ️';
    if (type === 'success') icon = '✅';
    if (type === 'error') icon = '❌';
    if (type === 'warning') icon = '⚠️';

    toast.innerHTML = `<span>${icon}</span> <span>${message}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

function copyToClipboard(text, message = 'Copiado com sucesso!') {
    navigator.clipboard.writeText(text).then(() => {
        showToast(message, 'success');
    }).catch(() => {
        showToast('Erro ao copiar para a área de transferência', 'error');
    });
}

async function loadStatus(silent = false) {
    try {
        const res = await fetch('api.php?action=status');
        const data = await res.json();

        if (data.success) {
            // Nuvemshop status
            const nuvemBadge = document.getElementById('nuvem-status-badge');
            const nuvemStore = document.getElementById('nuvem-store-name');
            if (data.nuvemshop.connected) {
                nuvemBadge.className = 'status-badge online';
                nuvemBadge.innerHTML = '<span class="status-dot"></span> Conectado';
                nuvemStore.textContent = data.nuvemshop.store_name || `Loja ID ${data.nuvemshop.store_id}`;
            } else {
                nuvemBadge.className = 'status-badge offline';
                nuvemBadge.innerHTML = '<span class="status-dot"></span> Desconectado';
                nuvemStore.textContent = data.nuvemshop.error || 'Erro de credencial';
            }

            // eGestor status
            const egestorBadge = document.getElementById('egestor-status-badge');
            const egestorInfo = document.getElementById('egestor-company-name');
            if (data.egestor.connected) {
                egestorBadge.className = 'status-badge online';
                egestorBadge.innerHTML = '<span class="status-dot"></span> Conectado';
                egestorInfo.textContent = data.egestor.company_name || 'Conexão ativa';
            } else if (!data.egestor.has_token) {
                egestorBadge.className = 'status-badge warning';
                egestorBadge.innerHTML = '<span class="status-dot"></span> Token Ausente';
                egestorInfo.textContent = 'Insira o Personal Token nas configurações';
            } else {
                egestorBadge.className = 'status-badge offline';
                egestorBadge.innerHTML = '<span class="status-dot"></span> Erro de Conexão';
                egestorInfo.textContent = data.egestor.error || 'Falha ao autenticar';
            }

            // Stats
            document.getElementById('stat-total-products').textContent = data.stats.total_mapped;
            document.getElementById('stat-last-sync').textContent = data.stats.last_sync ? data.stats.last_sync : 'Nenhuma';
            document.getElementById('stat-errors').textContent = data.stats.errors_24h;

            // Configuração padrão de estoque
            if (data.sync_only_with_stock !== undefined) {
                window.syncOnlyWithStock = !!data.sync_only_with_stock;
                const settingCheck = document.getElementById('input-sync-only-stock');
                if (settingCheck) settingCheck.checked = window.syncOnlyWithStock;
                const modalCheck = document.getElementById('check-only-stock');
                if (modalCheck) modalCheck.checked = window.syncOnlyWithStock;
            }
        }
    } catch (err) {
        if (!silent) showToast('Erro ao consultar status da API', 'error');
    }
}

let debounceTimerProducts = null;
function debounceLoadProducts() {
    clearTimeout(debounceTimerProducts);
    debounceTimerProducts = setTimeout(() => {
        loadProducts();
    }, 300);
}

function resetProductsFilters() {
    const s = document.getElementById('search-products');
    if (s) s.value = '';
    const st = document.getElementById('filter-products-stock');
    if (st) st.value = 'all';
    const sta = document.getElementById('filter-products-status');
    if (sta) sta.value = 'all';
    loadProducts();
}

async function loadProducts() {
    const tableBody = document.getElementById('products-table-body');
    if (!tableBody) return;

    tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--text-dim); padding: 25px;">Carregando produtos...</td></tr>';

    const searchInput = document.getElementById('search-products');
    const query = searchInput ? searchInput.value.trim() : '';
    const stockEl = document.getElementById('filter-products-stock');
    const stock = stockEl ? stockEl.value : 'all';
    const statusEl = document.getElementById('filter-products-status');
    const status = statusEl ? statusEl.value : 'all';

    try {
        const params = new URLSearchParams({ action: 'products' });
        if (query) params.append('search', query);
        if (stock !== 'all') params.append('stock', stock);
        if (status !== 'all') params.append('status', status);

        const res = await fetch(`api.php?${params.toString()}`);
        const data = await res.json();

        if (data.success && data.products.length > 0) {
            tableBody.innerHTML = data.products.map(p => {
                const stockVal = parseFloat(p.stock_nuvemshop || 0);
                let stockClass = 'in-stock';
                if (stockVal <= 0) stockClass = 'out-of-stock';
                else if (stockVal <= 3) stockClass = 'low-stock';

                let statusBadge = '<span class="status-badge warning"><span class="status-dot"></span> Pendente</span>';
                if (p.status === 'synced') {
                    statusBadge = '<span class="status-badge online"><span class="status-dot"></span> Sincronizado</span>';
                } else if (p.status === 'error') {
                    statusBadge = '<span class="status-badge offline"><span class="status-dot"></span> Erro</span>';
                }

                return `
                    <tr>
                        <td><strong>${p.sku || '-'}</strong></td>
                        <td>${p.barcode || '-'}</td>
                        <td>${escapeHtml(p.name)}</td>
                        <td><span class="stock-tag ${stockClass}">${stockVal} un</span></td>
                        <td>${p.price ? 'R$ ' + parseFloat(p.price).toFixed(2) : '-'}</td>
                        <td>${statusBadge}</td>
                        <td style="text-align: right; white-space: nowrap;">
                            <button class="btn btn-secondary btn-sm" onclick="syncSingleProduct('${p.egestor_id || p.sku}')" title="Sincronizar estoque do eGestor para a Nuvemshop">
                                🔄 Sincronizar
                            </button>
                            <button class="btn btn-danger btn-sm" style="margin-left: 6px;" onclick="removeProductFromNuvem('${p.nuvemshop_product_id}', '${escapeHtml(p.name)}')" title="Retirar produto da Nuvemshop (o eGestor não é alterado)">
                                🗑️ Retirar
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        } else {
            tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--text-dim); padding: 30px;">Nenhum produto encontrado com os filtros selecionados.</td></tr>';
        }
    } catch (err) {
        tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--danger);">Erro ao carregar produtos.</td></tr>';
    }
}

async function loadLogs() {
    const terminal = document.getElementById('logs-terminal');
    if (!terminal) return;

    try {
        const res = await fetch('api.php?action=logs');
        const data = await res.json();

        if (data.success && data.logs.length > 0) {
            terminal.innerHTML = data.logs.map(log => {
                let badgeClass = 'info';
                if (log.status === 'success') badgeClass = 'success';
                if (log.status === 'error') badgeClass = 'error';
                if (log.status === 'warning') badgeClass = 'warning';

                return `<div class="log-entry ${badgeClass}">
                    [${log.created_at}] [${log.source.toUpperCase()}] [${log.action}] ID: ${log.entity_id} - ${escapeHtml(log.details)}
                </div>`;
            }).join('');
        } else {
            terminal.innerHTML = '<div style="color: var(--text-dim); text-align: center; padding: 20px;">Nenhum log registrado ainda.</div>';
        }
    } catch (err) {
        terminal.innerHTML = '<div style="color: var(--danger);">Falha ao carregar registros de log.</div>';
    }
}

async function registerNuvemshopWebhooks() {
    const url = document.getElementById('nuvem-url-text').textContent.trim();
    if (url.includes('localhost') || url.includes('127.0.0.1')) {
        alert('Atenção: A Nuvemshop exige uma URL pública acessível pela internet com HTTPS (seu domínio na Hostinger ou um túnel ngrok).\n\nQuando subir para a Hostinger, clique neste botão para registrar os eventos de vendas automaticamente.');
        return;
    }

    showToast('Registrando webhooks de vendas na Nuvemshop...', 'info');
    try {
        const formData = new FormData();
        formData.append('url', url);

        const res = await fetch('api.php?action=register_nuvemshop_webhooks', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message, 'success');
            loadLogs();
        } else {
            showToast('Erro ao registrar: ' + (data.error || 'Falha'), 'error');
        }
    } catch (err) {
        showToast('Erro de comunicação ao registrar webhook na Nuvemshop', 'error');
    }
}

let isExportRunning = false;

function importEGestorToNuvemshop() {
    document.getElementById('export-setup-view').style.display = 'block';
    document.getElementById('export-progress-view').style.display = 'none';
    document.getElementById('export-egestor-modal').classList.add('active');
}

function closeExportModal() {
    if (isExportRunning) {
        if (!confirm('A importação está em andamento. Deseja realmente fechar e interromper?')) return;
        isExportRunning = false;
    }
    document.getElementById('export-egestor-modal').classList.remove('active');
}

function stopBatchExport() {
    isExportRunning = false;
    document.getElementById('export-progress-status').textContent = 'Interrompendo... aguarde a página atual finalizar.';
}

async function startBatchExport() {
    const onlyStock = document.getElementById('check-only-stock').checked;
    isExportRunning = true;

    document.getElementById('export-setup-view').style.display = 'none';
    document.getElementById('export-progress-view').style.display = 'block';

    let currentPage = 1;
    let lastPage = 1;
    let totalProcessed = 0;
    let totalCreated = 0;
    let totalUpdated = 0;
    let totalSkipped = 0;

    const term = document.getElementById('export-live-terminal');
    term.innerHTML = '<div style="color: #93c5fd;">Iniciando conexão com a API do eGestor e Nuvemshop...</div>';

    while (isExportRunning && currentPage <= lastPage) {
        document.getElementById('export-progress-status').textContent = `Processando página ${currentPage} de ${lastPage}...`;
        const pct = Math.round(((currentPage - 1) / Math.max(1, lastPage)) * 100);
        document.getElementById('export-progress-percent').textContent = `${pct}%`;
        document.getElementById('export-progress-bar').style.width = `${pct}%`;

        try {
            const formData = new FormData();
            formData.append('page', currentPage);
            if (onlyStock) formData.append('only_stock', '1');

            const res = await fetch('api.php?action=export_egestor_page', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (!data.success) {
                term.innerHTML += `<div style="color: #f87171;">[Erro Pág ${currentPage}] ${data.error || 'Falha ao buscar página'}</div>`;
                term.scrollTop = term.scrollHeight;
                break;
            }

            lastPage = data.last_page || 1;
            totalProcessed += data.processed || 0;
            totalCreated += data.created || 0;
            totalUpdated += data.updated || 0;
            totalSkipped += data.skipped || 0;

            document.getElementById('cnt-processed').textContent = totalProcessed;
            document.getElementById('cnt-created').textContent = totalCreated;
            document.getElementById('cnt-updated').textContent = totalUpdated;
            document.getElementById('cnt-skipped').textContent = totalSkipped;

            if (data.logs && data.logs.length > 0) {
                const logsHtml = data.logs.map(l => {
                    let color = '#d1d5db';
                    if (l.startsWith('Criado')) color = '#86efac';
                    if (l.startsWith('Atualizado')) color = '#93c5fd';
                    if (l.startsWith('Erro')) color = '#f87171';
                    if (l.startsWith('Ignorado')) color = '#6b7280';
                    return `<div style="color: ${color};">${escapeHtml(l)}</div>`;
                }).join('');
                term.innerHTML += logsHtml;
                term.scrollTop = term.scrollHeight;
            }

            currentPage++;
        } catch (err) {
            term.innerHTML += `<div style="color: #f87171;">Erro de rede na página ${currentPage}: ${err.message}</div>`;
            term.scrollTop = term.scrollHeight;
            break;
        }
    }

    isExportRunning = false;
    document.getElementById('export-progress-percent').textContent = '100%';
    document.getElementById('export-progress-bar').style.width = '100%';
    document.getElementById('export-progress-status').textContent = 'Importação Finalizada!';
    document.getElementById('btn-cancel-export').textContent = 'Fechar';
    document.getElementById('btn-cancel-export').onclick = closeExportModal;

    showToast(`Concluído! ${totalCreated} produtos novos criados, ${totalUpdated} atualizados.`, 'success');
    loadProducts();
    loadStatus(true);
    loadLogs();
}

async function importFromNuvemshop() {
    if (!confirm('Deseja importar todo o catálogo atual da Nuvemshop para a base de mapeamento?')) return;

    showToast('Importando catálogo da Nuvemshop... aguarde.', 'info');
    try {
        const res = await fetch('api.php?action=import_nuvemshop', { method: 'POST' });
        const data = await res.json();

        if (data.success) {
            showToast(`Sucesso! ${data.total} variantes mapeadas da Nuvemshop.`, 'success');
            loadProducts();
            loadStatus(true);
        } else {
            showToast('Erro ao importar: ' + (data.error || 'Desconhecido'), 'error');
        }
    } catch (err) {
        showToast('Erro de conexão ao importar catálogo.', 'error');
    }
}

async function syncSingleProduct(codigo) {
    if (!codigo) {
        showToast('Código de produto inválido', 'error');
        return;
    }

    showToast(`Sincronizando produto ${codigo}...`, 'info');
    try {
        const res = await fetch(`api.php?action=sync_product&codigo=${encodeURIComponent(codigo)}`, { method: 'POST' });
        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Produto sincronizado com sucesso!', 'success');
            loadProducts();
            loadStatus(true);
            const visorTab = document.getElementById('tab-visor');
            if (visorTab && visorTab.classList.contains('active')) {
                loadVisorProducts(window.currentVisorPage || 1);
            }
        } else {
            showToast('Falha: ' + (data.error || 'Erro desconhecido'), 'error');
        }
    } catch (err) {
        showToast('Erro na requisição de sincronização', 'error');
    }
}

// Remove produto da Nuvemshop (o eGestor nunca é alterado)
async function removeProductFromNuvem(productId, productName) {
    if (!productId) {
        showToast('ID da Nuvemshop inválido para remoção', 'error');
        return;
    }

    const conf = confirm(`⚠️ Confirmação de Retirada:\n\nDeseja realmente excluir o produto "${productName}" (ID #${productId}) da Nuvemshop?\n\n🛡️ Segurança: O produto continuará 100% INTACTO no seu eGestor.`);
    if (!conf) return;

    showToast(`Removendo produto #${productId} da Nuvemshop...`, 'info');

    try {
        const formData = new FormData();
        formData.append('id', productId);

        const res = await fetch('api.php?action=delete_nuvem_product', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Produto retirado da Nuvemshop com sucesso!', 'success');
            loadProducts();
            loadStatus(true);
            const visorTab = document.getElementById('tab-visor');
            if (visorTab && visorTab.classList.contains('active')) {
                loadVisorProducts(window.currentVisorPage || 1);
            }
        } else {
            showToast('Erro ao remover: ' + (data.error || 'Falha'), 'error');
        }
    } catch (err) {
        showToast('Erro de comunicação ao excluir produto da Nuvemshop', 'error');
    }
}

// Modal Envio Manual
function openManualSendModal(codigo = '') {
    const input = document.getElementById('manual-send-codigo');
    if (input && codigo) input.value = codigo;
    document.getElementById('manual-send-modal').classList.add('active');
}

function closeManualSendModal() {
    document.getElementById('manual-send-modal').classList.remove('active');
}

async function submitManualSend(e) {
    e.preventDefault();
    const codigo = document.getElementById('manual-send-codigo').value.trim();
    const force = document.getElementById('manual-send-force').checked;

    if (!codigo) {
        showToast('Informe o código do produto no eGestor', 'error');
        return;
    }

    const submitBtn = document.getElementById('btn-manual-send-submit');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Enviando...';
    }

    await sendProductToNuvem(codigo, force);

    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = '📤 Enviar Agora';
    }
    closeManualSendModal();
}

// Envia produto individual do eGestor para a Nuvemshop
async function sendProductToNuvem(codigo, force = true) {
    if (!codigo) {
        showToast('Código inválido', 'error');
        return;
    }

    showToast(`Buscando e enviando produto ${codigo} para a Nuvemshop...`, 'info');

    try {
        const formData = new FormData();
        formData.append('codigo', codigo);
        formData.append('force', force ? '1' : '0');

        const res = await fetch('api.php?action=sync_product', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Produto enviado para a Nuvemshop com sucesso!', 'success');
            loadProducts();
            loadStatus(true);
            const visorTab = document.getElementById('tab-visor');
            if (visorTab && visorTab.classList.contains('active')) {
                loadVisorProducts(window.currentVisorPage || 1);
            }
        } else {
            showToast('Falha no envio: ' + (data.error || 'Erro desconhecido'), 'error');
        }
    } catch (err) {
        showToast('Erro de comunicação ao enviar produto', 'error');
    }
}

function resetVisorFilters() {
    const s = document.getElementById('search-visor');
    if (s) s.value = '';
    const st = document.getElementById('filter-visor-status');
    if (st) st.value = 'missing';
    const sk = document.getElementById('filter-visor-stock');
    if (sk) sk.value = 'all';
    loadVisorProducts(1);
}

function openSyncModal() {
    importEGestorToNuvemshop();
}

// Vizor: Carrega lista comparativa eGestor vs Nuvemshop
window.currentVisorPage = 1;
async function loadVisorProducts(page = 1) {
    window.currentVisorPage = page;
    const tableBody = document.getElementById('visor-table-body');
    const summaryText = document.getElementById('visor-summary-text');
    const pagInfo = document.getElementById('visor-pagination-info');
    const pagTop = document.getElementById('visor-pagination-top');
    const pagBottom = document.getElementById('visor-pagination-bottom');

    if (!tableBody) return;

    tableBody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: var(--text-dim); padding: 25px;">Carregando produtos do eGestor...</td></tr>';
    if (summaryText) summaryText.textContent = 'Consultando API do eGestor...';

    const filterInput = document.getElementById('search-visor');
    const filter = filterInput ? filterInput.value.trim() : '';
    const statusEl = document.getElementById('filter-visor-status');
    const status = statusEl ? statusEl.value : 'missing';
    const stockEl = document.getElementById('filter-visor-stock');
    const stock = stockEl ? stockEl.value : 'all';

    try {
        const params = new URLSearchParams({
            action: 'egestor_vs_nuvem',
            page: page,
            filter: filter,
            status: status,
            stock: stock
        });

        const res = await fetch(`api.php?${params.toString()}`);
        const data = await res.json();

        if (!data.success) {
            tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--danger); padding: 25px;">Erro ao consultar eGestor: ${escapeHtml(data.error || 'Falha')}</td></tr>`;
            if (summaryText) summaryText.textContent = 'Erro ao carregar catálogo';
            return;
        }

        const items = data.items || [];
        const totalEGestor = data.total_egestor || 0;
        const lastPage = data.last_page || 1;

        if (summaryText) {
            let filterDesc = [];
            if (status === 'missing') filterDesc.push('apenas NÃO enviados para Nuvem');
            else if (status === 'in_nuvem') filterDesc.push('apenas JÁ enviados para Nuvem');
            if (stock === 'has_stock') filterDesc.push('com estoque > 0');
            else if (stock === 'zero_stock') filterDesc.push('sem estoque / zerados');
            else if (stock === 'low_stock') filterDesc.push('estoque baixo');

            const filterStr = filterDesc.length ? ` &bull; <span style="color:#fde047;">(${filterDesc.join(', ')})</span>` : '';
            summaryText.innerHTML = `Total no eGestor: <strong>${totalEGestor.toLocaleString()}</strong> | Exibindo página <strong>${page}</strong> de <strong>${lastPage}</strong>${filterStr}`;
        }

        if (pagInfo) {
            pagInfo.textContent = `Página ${page} de ${lastPage} (${totalEGestor.toLocaleString()} itens no eGestor)`;
        }

        const renderPaginationBtns = () => {
            let btns = '';
            if (page > 1) {
                btns += `<button class="btn btn-secondary btn-sm" onclick="loadVisorProducts(${page - 1})">&laquo; Anterior</button>`;
            }
            if (page < lastPage) {
                btns += `<button class="btn btn-secondary btn-sm" onclick="loadVisorProducts(${page + 1})">Próxima &raquo;</button>`;
            }
            return btns;
        };

        if (pagTop) pagTop.innerHTML = renderPaginationBtns();
        if (pagBottom) pagBottom.innerHTML = renderPaginationBtns();

        if (items.length === 0) {
            tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-dim); padding: 30px;">Nenhum produto encontrado nesta página com os filtros selecionados.</td></tr>`;
            return;
        }

        tableBody.innerHTML = items.map(p => {
            const stockVal = parseFloat(p.estoque || 0);
            let stockBadge = '<span class="stock-tag in-stock">' + stockVal + ' un</span>';
            if (stockVal <= 0) stockBadge = '<span class="stock-tag out-of-stock">0 un</span>';
            else if (stockVal <= 3) stockBadge = '<span class="stock-tag low-stock">' + stockVal + ' un</span>';

            let statusHtml = '';
            let actionHtml = '';

            if (p.in_nuvemshop) {
                statusHtml = `<span class="status-badge online"><span class="status-dot"></span> Na Nuvemshop (#${p.nuvemshop_product_id})</span>`;
                actionHtml = `
                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                        <button class="btn btn-secondary btn-sm" onclick="syncSingleProduct('${p.codigo}')" title="Sincronizar estoque do eGestor para a Nuvemshop">
                            🔄
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="removeProductFromNuvem('${p.nuvemshop_product_id}', '${escapeHtml(p.nome)}')" title="Retirar da Nuvemshop (mantém 100% no eGestor)">
                            🗑️ Retirar da Nuvem
                        </button>
                    </div>
                `;
            } else {
                statusHtml = `<span class="status-badge warning" style="color: #fde047;"><span class="status-dot"></span> Não Enviado</span>`;
                actionHtml = `
                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                        <button class="btn btn-success btn-sm" onclick="sendProductToNuvem('${p.codigo}', true)" title="Enviar este produto manualmente para a Nuvemshop">
                            📤 Enviar para Nuvem
                        </button>
                    </div>
                `;
            }

            return `
                <tr>
                    <td><strong>${p.codigo}</strong></td>
                    <td>${p.sku || '-'}</td>
                    <td>${p.barcode || '-'}</td>
                    <td>${escapeHtml(p.nome)}</td>
                    <td>${stockBadge}</td>
                    <td>${p.preco ? 'R$ ' + parseFloat(p.preco).toFixed(2) : '-'}</td>
                    <td>${statusHtml}</td>
                    <td style="text-align: right;">${actionHtml}</td>
                </tr>
            `;
        }).join('');

    } catch (err) {
        tableBody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: var(--danger); padding: 25px;">Erro de conexão ao consultar produtos do eGestor.</td></tr>';
    }
}

// Modal Settings
function openSettingsModal() {
    document.getElementById('settings-modal').classList.add('active');
}

function closeSettingsModal() {
    document.getElementById('settings-modal').classList.remove('active');
}

async function saveSettings(e) {
    e.preventDefault();
    const token = document.getElementById('input-personal-token').value.trim();
    const onlyStockEl = document.getElementById('input-sync-only-stock');
    const onlyStock = onlyStockEl ? onlyStockEl.checked : true;

    try {
        const formData = new FormData();
        formData.append('egestor_personal_token', token);
        formData.append('sync_only_with_stock', onlyStock ? '1' : '0');

        const res = await fetch('api.php?action=save_settings', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast('Configurações salvas com sucesso!', 'success');
            closeSettingsModal();
            loadStatus();
        } else {
            showToast('Erro ao salvar: ' + (data.error || 'Desconhecido'), 'error');
        }
    } catch (err) {
        showToast('Erro de conexão ao salvar configurações', 'error');
    }
}

// Modal Criar Produto Nuvemshop
function openCreateProductModal() {
    document.getElementById('create-product-modal').classList.add('active');
}

function closeCreateProductModal() {
    document.getElementById('create-product-modal').classList.remove('active');
}

async function submitCreateProduct(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);

    showToast('Cadastrando produto na Nuvemshop...', 'info');

    try {
        const res = await fetch('api.php?action=create_product_nuvem', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast('Produto cadastrado na Nuvemshop com sucesso!', 'success');
            closeCreateProductModal();
            form.reset();
            loadProducts();
            loadStatus(true);
        } else {
            showToast('Erro ao criar: ' + (data.error || 'Desconhecido'), 'error');
        }
    } catch (err) {
        showToast('Erro de comunicação com o servidor', 'error');
    }
}

// Modal Teste de Webhook
function openTestWebhookModal() {
    document.getElementById('test-webhook-modal').classList.add('active');
}

function closeTestWebhookModal() {
    document.getElementById('test-webhook-modal').classList.remove('active');
}

async function submitTestWebhook(e) {
    e.preventDefault();
    const codigo = document.getElementById('test-webhook-codigo').value.trim();

    showToast(`Simulando webhook do eGestor para produto ${codigo}...`, 'info');
    try {
        const formData = new FormData();
        formData.append('codigo', codigo);

        const res = await fetch('api.php?action=test_webhook', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast('Webhook simulado executado com sucesso: ' + (data.message || 'OK'), 'success');
            closeTestWebhookModal();
            loadProducts();
            loadLogs();
        } else {
            showToast('Falha no teste: ' + (data.error || 'Erro desconhecido'), 'error');
        }
    } catch (err) {
        showToast('Erro ao disparar webhook de teste', 'error');
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// ========================================================
// CADASTRO DE PRODUTO NOS MOLDES DA NUVEMSHOP
// ========================================================

function switchToNewProductTab() {
    const tabBtn = document.querySelector('.tab-btn[data-tab="tab-novo-produto"]');
    if (tabBtn) tabBtn.click();
    setTimeout(() => {
        const nameInput = document.getElementById('prod-name');
        if (nameInput) nameInput.focus();
    }, 100);
}

function initNewProductForm() {
    generateVariantsTable();
}

function toggleHasVariants(hasVariants) {
    const simpleBlock = document.getElementById('simple-product-block');
    const variantsBlock = document.getElementById('variants-product-block');
    if (simpleBlock) simpleBlock.style.display = hasVariants ? 'none' : 'block';
    if (variantsBlock) variantsBlock.style.display = hasVariants ? 'block' : 'none';
}

function toggleProp2(enabled) {
    const prop2Box = document.getElementById('prop-2-box');
    if (prop2Box) prop2Box.style.display = enabled ? 'block' : 'none';
    generateVariantsTable();
}

function onBasePriceChange() {
    const basePrice = document.getElementById('prod-price').value;
    if (!basePrice) return;
    const priceInputs = document.querySelectorAll('.variant-val-price');
    priceInputs.forEach(input => {
        if (!input.dataset.userEdited) {
            input.value = parseFloat(basePrice).toFixed(2);
        }
    });
}

// Armazena temporariamente valores digitados para não perder ao editar texto de opções
window.variantCustomData = window.variantCustomData || {};

function generateVariantsTable() {
    const tbody = document.getElementById('variants-tbody');
    const badge = document.getElementById('variants-count-badge');
    if (!tbody) return;

    // Salva o que já foi digitado pelo usuário nas linhas existentes
    document.querySelectorAll('.variant-row').forEach(row => {
        const key = row.dataset.comboKey;
        if (!key) return;
        const priceInput = row.querySelector('.variant-val-price');
        const stockInput = row.querySelector('.variant-val-stock');
        const skuInput = row.querySelector('.variant-val-sku');
        const barcodeInput = row.querySelector('.variant-val-barcode');
        window.variantCustomData[key] = {
            price: priceInput ? priceInput.value : '',
            stock: stockInput ? stockInput.value : '',
            sku: skuInput ? skuInput.value : '',
            barcode: barcodeInput ? barcodeInput.value : '',
            userEdited: priceInput ? !!priceInput.dataset.userEdited : false
        };
    });

    const prop1Name = (document.getElementById('prop-1-name')?.value || 'Cor').trim();
    const prop1Raw = document.getElementById('prop-1-values')?.value || '';
    const prop1Values = prop1Raw.split(',').map(s => s.trim()).filter(s => s !== '');

    const prop2Enabled = document.getElementById('chk-enable-prop-2')?.checked ?? true;
    const prop2Name = (document.getElementById('prop-2-name')?.value || 'Tamanho').trim();
    const prop2Raw = document.getElementById('prop-2-values')?.value || '';
    const prop2Values = prop2Enabled ? prop2Raw.split(',').map(s => s.trim()).filter(s => s !== '') : [];

    const basePrice = parseFloat(document.getElementById('prod-price')?.value || 0).toFixed(2);

    let combinations = [];
    if (prop1Values.length > 0 && prop2Values.length > 0) {
        prop1Values.forEach(v1 => {
            prop2Values.forEach(v2 => {
                combinations.push({
                    values: [v1, v2],
                    key: `${v1}___${v2}`,
                    label: `${v1} / ${v2}`
                });
            });
        });
    } else if (prop1Values.length > 0) {
        prop1Values.forEach(v1 => {
            combinations.push({
                values: [v1],
                key: `${v1}`,
                label: `${v1}`
            });
        });
    } else if (prop2Values.length > 0) {
        prop2Values.forEach(v2 => {
            combinations.push({
                values: [v2],
                key: `${v2}`,
                label: `${v2}`
            });
        });
    }

    if (badge) badge.textContent = combinations.length;

    if (combinations.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-dim); padding: 20px;">Nenhuma opção definida. Preencha os valores acima (ex: Verde, Rosa, Preto).</td></tr>`;
        return;
    }

    tbody.innerHTML = combinations.map(c => {
        const prev = window.variantCustomData[c.key] || {};
        const priceVal = prev.price !== undefined && prev.price !== '' ? prev.price : (basePrice > 0 ? basePrice : '0.00');
        const stockVal = prev.stock !== undefined && prev.stock !== '' ? prev.stock : '1';
        const skuVal = prev.sku || '';
        const barcodeVal = prev.barcode || '';
        const userEditedAttr = prev.userEdited ? 'data-user-edited="true"' : '';

        return `
            <tr class="variant-row" data-combo-key="${escapeHtml(c.key)}" data-values='${escapeHtml(JSON.stringify(c.values))}'>
                <td style="font-weight: 500;">
                    <span style="display: inline-block; background: rgba(255,255,255,0.08); padding: 3px 8px; border-radius: 4px;">
                        ${escapeHtml(c.label)}
                    </span>
                </td>
                <td>
                    <input type="number" step="0.01" min="0" class="form-control variant-val-price" value="${priceVal}" placeholder="0.00" ${userEditedAttr} onchange="this.dataset.userEdited='true'">
                </td>
                <td>
                    <input type="number" step="1" min="0" class="form-control variant-val-stock" value="${stockVal}" placeholder="0" required>
                </td>
                <td>
                    <input type="text" class="form-control variant-val-sku" value="${escapeHtml(skuVal)}" placeholder="Auto">
                </td>
                <td>
                    <input type="text" class="form-control variant-val-barcode" value="${escapeHtml(barcodeVal)}" placeholder="Opcional">
                </td>
            </tr>
        `;
    }).join('');
}

async function submitFullProduct(e) {
    e.preventDefault();

    const name = document.getElementById('prod-name').value.trim();
    if (!name) {
        showToast('O nome do produto é obrigatório.', 'error');
        document.getElementById('prod-name').focus();
        return;
    }

    const price = parseFloat(document.getElementById('prod-price').value || 0);
    if (price <= 0) {
        showToast('Informe um preço de venda válido maior que zero.', 'error');
        document.getElementById('prod-price').focus();
        return;
    }

    const costPrice = parseFloat(document.getElementById('prod-cost-price')?.value || 0);
    const description = document.getElementById('prod-description')?.value.trim() || '';

    // Imagens (uma por linha)
    const imagesRaw = document.getElementById('prod-images')?.value || '';
    const images = imagesRaw.split('\n').map(s => s.trim()).filter(s => s.startsWith('http'));

    const hasVariants = document.getElementById('chk-has-variants')?.checked ?? false;

    let payload = {
        name: name,
        description: description,
        price: price,
        cost_price: costPrice,
        images: images,
        has_variants: hasVariants
    };

    if (hasVariants) {
        const prop1Name = (document.getElementById('prop-1-name')?.value || 'Cor').trim();
        const prop2Enabled = document.getElementById('chk-enable-prop-2')?.checked ?? true;
        const prop2Name = (document.getElementById('prop-2-name')?.value || 'Tamanho').trim();

        const attributes = [];
        if (prop1Name) attributes.push(prop1Name);
        if (prop2Enabled && prop2Name) attributes.push(prop2Name);

        const rows = document.querySelectorAll('.variant-row');
        if (rows.length === 0) {
            showToast('Nenhuma variação gerada para a grade.', 'error');
            return;
        }

        const variants = [];
        for (const row of rows) {
            const rawValues = JSON.parse(row.dataset.values || '[]');
            const priceInput = row.querySelector('.variant-val-price');
            const stockInput = row.querySelector('.variant-val-stock');
            const skuInput = row.querySelector('.variant-val-sku');
            const barcodeInput = row.querySelector('.variant-val-barcode');

            const varPrice = priceInput && priceInput.value !== '' ? parseFloat(priceInput.value) : price;
            const varStock = stockInput && stockInput.value !== '' ? parseFloat(stockInput.value) : 0;
            const varSku = skuInput ? skuInput.value.trim() : '';
            const varBarcode = barcodeInput ? barcodeInput.value.trim() : '';

            variants.push({
                values: rawValues,
                price: varPrice,
                cost_price: costPrice,
                stock: varStock,
                sku: varSku,
                barcode: varBarcode
            });
        }

        payload.attributes = attributes;
        payload.variants = variants;
    } else {
        // Produto Simples
        payload.stock = parseFloat(document.getElementById('prod-simple-stock')?.value || 0);
        payload.sku = document.getElementById('prod-simple-sku')?.value.trim() || '';
        payload.barcode = document.getElementById('prod-simple-barcode')?.value.trim() || '';
    }

    const btn = document.getElementById('btn-submit-product');
    const statusBox = document.getElementById('create-product-status-box');
    const statusText = document.getElementById('create-product-status-text');

    const originalBtnHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Cadastrando no eGestor & Nuvemshop...';

    if (statusBox && statusText) {
        statusBox.style.display = 'block';
        statusText.innerHTML = `
            <strong>Processando Cadastro:</strong><br>
            1. Salvando ${hasVariants ? payload.variants.length + ' variações individuais' : 'produto'} no eGestor...<br>
            2. Criando anúncio unificado na Nuvemshop...<br>
            3. Gravando sincronização de estoque...
        `;
    }

    try {
        const res = await fetch('api.php?action=create_full_product', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Produto cadastrado com sucesso!', 'success');
            if (statusBox && statusText) {
                statusText.innerHTML = `✅ <strong>Sucesso!</strong> Produto criado com êxito no eGestor e na Nuvemshop.`;
                setTimeout(() => { statusBox.style.display = 'none'; }, 6000);
            }

            // Limpa formulário
            document.getElementById('form-create-full-product').reset();
            window.variantCustomData = {};
            generateVariantsTable();

            // Atualiza status e tabelas
            loadProducts();
            loadStatus(true);
            loadLogs();

            // Notifica e sugere ir para a aba de produtos
            setTimeout(() => {
                const goTab = confirm('Produto cadastrado com sucesso no eGestor e na Nuvemshop! Deseja visualizar na lista de produtos?');
                if (goTab) {
                    const prodTabBtn = document.querySelector('.tab-btn[data-tab="tab-products"]');
                    if (prodTabBtn) prodTabBtn.click();
                }
            }, 500);

        } else {
            showToast('Erro ao cadastrar: ' + (data.error || 'Falha no processo'), 'error');
            if (statusBox && statusText) {
                statusText.innerHTML = `❌ <strong>Erro:</strong> ${escapeHtml(data.error || 'Falha ao processar')}`;
            }
        }
    } catch (err) {
        showToast('Erro de comunicação com o servidor ao cadastrar produto.', 'error');
        if (statusBox && statusText) {
            statusText.innerHTML = `❌ <strong>Erro:</strong> Falha de conexão com a API.`;
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
    }
}
