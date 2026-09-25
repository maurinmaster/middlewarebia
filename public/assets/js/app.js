document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    loadStatus();
    loadProducts();
    loadLogs();

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
        }
    } catch (err) {
        if (!silent) showToast('Erro ao consultar status da API', 'error');
    }
}

async function loadProducts(query = '') {
    const tableBody = document.getElementById('products-table-body');
    if (!tableBody) return;

    tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--text-dim);">Carregando produtos...</td></tr>';

    try {
        const url = query ? `api.php?action=products&search=${encodeURIComponent(query)}` : 'api.php?action=products';
        const res = await fetch(url);
        const data = await res.json();

        if (data.success && data.products.length > 0) {
            tableBody.innerHTML = data.products.map(p => {
                const stock = parseFloat(p.stock_nuvemshop || 0);
                let stockClass = 'in-stock';
                if (stock <= 0) stockClass = 'out-of-stock';
                else if (stock <= 3) stockClass = 'low-stock';

                const statusBadge = p.status === 'synced'
                    ? '<span class="status-badge online"><span class="status-dot"></span> Sincronizado</span>'
                    : '<span class="status-badge warning"><span class="status-dot"></span> Pendente</span>';

                return `
                    <tr>
                        <td><strong>${p.sku || '-'}</strong></td>
                        <td>${p.barcode || '-'}</td>
                        <td>${escapeHtml(p.name)}</td>
                        <td><span class="stock-tag ${stockClass}">${stock} un</span></td>
                        <td>${p.price ? 'R$ ' + parseFloat(p.price).toFixed(2) : '-'}</td>
                        <td>${statusBadge}</td>
                        <td style="text-align: right;">
                            <button class="btn btn-secondary btn-sm" onclick="syncSingleProduct('${p.egestor_id || p.sku}')" title="Sincronizar estoque agora">
                                🔄 Sincronizar
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        } else {
            tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--text-dim); padding: 30px;">Nenhum produto sincronizado ainda. Clique em "Importar da Nuvemshop" ou envie um produto do eGestor.</td></tr>';
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

async function importEGestorToNuvemshop() {
    if (!confirm('Deseja importar TODOS os produtos do eGestor para a Nuvemshop?\n\n- Produtos novos serão cadastrados na Nuvemshop (com preço, estoque e fotos).\n- Produtos já existentes terão seu estoque atualizado de acordo com o eGestor.')) {
        return;
    }

    showToast('Importando catálogo do eGestor para a Nuvemshop... isso pode levar alguns minutos.', 'info');
    try {
        const res = await fetch('api.php?action=export_egestor_to_nuvem', { method: 'POST' });
        const data = await res.json();

        if (data.success) {
            showToast(data.message, 'success');
            loadProducts();
            loadStatus(true);
            loadLogs();
        } else {
            showToast('Erro na importação: ' + (data.error || 'Falha'), 'error');
        }
    } catch (err) {
        showToast('Erro de comunicação ao processar importação.', 'error');
    }
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
        } else {
            showToast('Falha: ' + (data.error || 'Erro desconhecido'), 'error');
        }
    } catch (err) {
        showToast('Erro na requisição de sincronização', 'error');
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

    try {
        const formData = new FormData();
        formData.append('egestor_personal_token', token);

        const res = await fetch('api.php?action=save_settings', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast('Configurações atualizadas com sucesso!', 'success');
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
