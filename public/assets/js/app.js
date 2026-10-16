document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    loadStatus();
    loadProductsList(1);
    initNewProductForm();

    // Auto-refresh status e logs a cada 30 segundos
    setInterval(() => {
        loadStatus(true);
        const logsTab = document.getElementById('tab-logs');
        if (logsTab && logsTab.classList.contains('active')) {
            loadLogs();
        }
    }, 30000);
});

// ========================================================
// CONTROLE DE NAVEGAÇÃO LATERAL (TABS)
// ========================================================
const pageHeaders = {
    'tab-lista-produtos': {
        title: 'Lista de Produtos',
        desc: 'Catálogo do eGestor integrado à Nuvemshop com controle unificado de estoques'
    },
    'tab-novo-produto': {
        title: 'Cadastrar Produto',
        desc: 'Cadastro simultâneo no eGestor e anúncio com grade unificada na Nuvemshop'
    },
    'tab-logs': {
        title: 'Logs do Sistema',
        desc: 'Monitor de eventos, webhooks e requisições em tempo real'
    },
    'tab-config': {
        title: 'Configurações & Webhooks',
        desc: 'Parâmetros de conexão, chaves de API e URLs de webhooks'
    },
    'tab-guide': {
        title: 'Guia de Configuração do eGestor',
        desc: 'Passo a passo para ativar o envio automático de webhooks no eGestor'
    }
};

function initTabs() {
    const navButtons = document.querySelectorAll('.nav-link-btn');
    navButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-tab');
            switchTab(targetId);
        });
    });
}

function switchTab(targetId) {
    const navButtons = document.querySelectorAll('.nav-link-btn');
    const tabContents = document.querySelectorAll('.tab-content');

    navButtons.forEach(b => {
        if (b.getAttribute('data-tab') === targetId) {
            b.classList.add('active');
        } else {
            b.classList.remove('active');
        }
    });

    tabContents.forEach(c => {
        if (c.id === targetId) {
            c.classList.add('active');
        } else {
            c.classList.remove('active');
        }
    });

    // Atualiza cabeçalho da página
    const titleEl = document.getElementById('page-current-title');
    const descEl = document.getElementById('page-current-desc');
    const meta = pageHeaders[targetId];
    if (meta && titleEl && descEl) {
        titleEl.textContent = meta.title;
        descEl.textContent = meta.desc;
    }

    if (targetId === 'tab-lista-produtos') {
        loadProductsList(window.currentProductsPage || 1);
    } else if (targetId === 'tab-novo-produto') {
        generateVariantsTable();
    } else if (targetId === 'tab-logs') {
        loadLogs();
    }
}

function switchToNewProductTab() {
    switchTab('tab-novo-produto');
    setTimeout(() => {
        const nameInput = document.getElementById('prod-name');
        if (nameInput) nameInput.focus();
    }, 100);
}

function refreshCurrentView() {
    loadStatus(true);
    const activeTab = document.querySelector('.tab-content.active');
    if (activeTab) {
        if (activeTab.id === 'tab-lista-produtos') {
            loadProductsList(window.currentProductsPage || 1);
        } else if (activeTab.id === 'tab-logs') {
            loadLogs();
        }
    }
    showToast('Dados atualizados com sucesso!', 'info');
}

// ========================================================
// TOAST NOTIFICAÇÕES
// ========================================================
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

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// ========================================================
// STATUS E CONEXÕES
// ========================================================
async function loadStatus(silent = false) {
    try {
        const res = await fetch('api.php?action=status');
        const data = await res.json();

        if (data.success) {
            // Nuvemshop
            const nuvemBadge = document.getElementById('nuvem-status-badge');
            const nuvemStore = document.getElementById('nuvem-store-name');
            if (data.nuvemshop.connected) {
                nuvemBadge.className = 'status-badge online';
                nuvemBadge.innerHTML = '<span class="status-dot"></span> Online';
                nuvemStore.textContent = data.nuvemshop.store_name || `Loja ID ${data.nuvemshop.store_id}`;
            } else {
                nuvemBadge.className = 'status-badge offline';
                nuvemBadge.innerHTML = '<span class="status-dot"></span> Erro';
                nuvemStore.textContent = data.nuvemshop.error || 'Desconectado';
            }

            // eGestor
            const egestorBadge = document.getElementById('egestor-status-badge');
            const egestorCompany = document.getElementById('egestor-company-name');
            if (data.egestor.connected) {
                egestorBadge.className = 'status-badge online';
                egestorBadge.innerHTML = '<span class="status-dot"></span> Online';
                egestorCompany.textContent = data.egestor.company_name || 'Conectado';
            } else {
                egestorBadge.className = 'status-badge offline';
                egestorBadge.innerHTML = '<span class="status-dot"></span> Erro';
                egestorCompany.textContent = data.egestor.error || 'Token Inválido';
            }

            // Estatísticas Topbar
            const topbarNuvem = document.getElementById('topbar-total-nuvem');
            if (topbarNuvem) {
                topbarNuvem.textContent = data.stats.total_mapped.toLocaleString();
            }

            const topbarLastSync = document.getElementById('topbar-last-sync');
            if (topbarLastSync && data.stats.last_sync) {
                const parts = data.stats.last_sync.split(' ');
                topbarLastSync.textContent = parts[1] || 'Hoje';
            }

            // Regra no form de config
            const syncRuleEl = document.getElementById('input-sync-only-stock');
            if (syncRuleEl && data.sync_only_with_stock !== undefined) {
                syncRuleEl.checked = data.sync_only_with_stock;
            }
        }
    } catch (err) {
        if (!silent) {
            console.error('Falha ao checar status:', err);
        }
    }
}

// ========================================================
// LISTA DE PRODUTOS CONSOLIDADA (EGESTOR VS NUVEMSHOP)
// ========================================================
window.currentProductsPage = 1;

async function loadProductsList(page = 1) {
    window.currentProductsPage = page;
    const tableBody = document.getElementById('products-list-tbody');
    const summaryText = document.getElementById('products-list-summary');
    const pagTop = document.getElementById('pagination-top-btns');
    const pagBottom = document.getElementById('pagination-bottom-btns');
    const pagInfo = document.getElementById('pagination-bottom-info');

    if (!tableBody) return;

    tableBody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: var(--text-dim); padding: 30px;">Consultando produtos no eGestor...</td></tr>';
    if (summaryText) summaryText.textContent = 'Consultando API do eGestor...';

    const filterInput = document.getElementById('search-products-list');
    const filter = filterInput ? filterInput.value.trim() : '';
    const statusEl = document.getElementById('filter-status-nuvem');
    const status = statusEl ? statusEl.value : 'all';
    const stockEl = document.getElementById('filter-stock-level');
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
            tableBody.innerHTML = `<tr><td colspan="9" style="text-align: center; color: var(--danger); padding: 30px;">Erro ao consultar eGestor: ${escapeHtml(data.error || 'Falha')}</td></tr>`;
            if (summaryText) summaryText.textContent = 'Erro ao carregar catálogo';
            return;
        }

        const items = data.items || [];
        const totalEGestor = data.total_egestor || 0;
        const lastPage = data.last_page || 1;

        // Atualiza contadores
        const topbarEGestor = document.getElementById('topbar-total-egestor');
        if (topbarEGestor) topbarEGestor.textContent = totalEGestor.toLocaleString();

        if (summaryText) {
            let filterDesc = [];
            if (status === 'missing') filterDesc.push('apenas não enviados');
            else if (status === 'in_nuvem') filterDesc.push('apenas na Nuvemshop');
            if (stock === 'has_stock') filterDesc.push('com estoque > 0');
            else if (stock === 'zero_stock') filterDesc.push('estoque zerado');
            else if (stock === 'low_stock') filterDesc.push('estoque baixo (1 a 3)');

            const filterStr = filterDesc.length ? ` &bull; <span style="color: #2563eb; font-weight: 600;">(${filterDesc.join(', ')})</span>` : '';
            const scopeLabel = status === 'in_nuvem' ? 'Total na Nuvemshop' : 'Total no eGestor';
            summaryText.innerHTML = `${scopeLabel}: <strong>${totalEGestor.toLocaleString()}</strong> produtos &bull; Exibindo página <strong>${page}</strong> de <strong>${lastPage}</strong>${filterStr}`;
        }

        if (pagInfo) {
            const scopeInfo = status === 'in_nuvem' ? 'produtos sincronizados na Nuvemshop' : 'produtos cadastrados no eGestor';
            pagInfo.textContent = `Página ${page} de ${lastPage} (${totalEGestor.toLocaleString()} ${scopeInfo})`;
        }

        const renderPaginationBtns = () => {
            let btns = '';
            if (page > 1) {
                btns += `<button class="btn btn-secondary btn-sm" onclick="loadProductsList(${page - 1})">&laquo; Anterior</button>`;
            }
            if (page < lastPage) {
                btns += `<button class="btn btn-secondary btn-sm" onclick="loadProductsList(${page + 1})">Próxima &raquo;</button>`;
            }
            return btns;
        };

        if (pagTop) pagTop.innerHTML = renderPaginationBtns();
        if (pagBottom) pagBottom.innerHTML = renderPaginationBtns();

        if (items.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: var(--text-dim); padding: 35px;">Nenhum produto encontrado nesta página com os filtros selecionados.</td></tr>';
            return;
        }

        tableBody.innerHTML = items.map(p => {
            const stockVal = parseFloat(p.estoque || 0);
            let stockBadge = '<span class="stock-tag in-stock">' + stockVal + ' un</span>';
            if (stockVal <= 0) stockBadge = '<span class="stock-tag out-of-stock">0 un</span>';
            else if (stockVal <= 3) stockBadge = '<span class="stock-tag low-stock">' + stockVal + ' un</span>';

            let nuvemStockVal = p.stock_nuvemshop !== null && p.stock_nuvemshop !== undefined ? parseFloat(p.stock_nuvemshop) : null;
            let nuvemStockBadge = '<span style="color: #94a3b8;">-</span>';
            if (p.in_nuvemshop && nuvemStockVal !== null) {
                let nClass = 'in-stock';
                if (nuvemStockVal <= 0) nClass = 'out-of-stock';
                else if (nuvemStockVal <= 3) nClass = 'low-stock';
                nuvemStockBadge = `<span class="stock-tag ${nClass}">${nuvemStockVal} un</span>`;
            }

            let statusHtml = '';
            let actionHtml = '';

            if (p.in_nuvemshop) {
                statusHtml = `<span class="status-badge online"><span class="status-dot"></span> Na Nuvemshop (#${p.nuvemshop_product_id})</span>`;
                actionHtml = `
                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                        <button class="btn btn-secondary btn-sm" onclick="syncSingleProduct('${p.codigo}')" title="Sincronizar estoque do eGestor para a Nuvemshop">
                            🔄 Sincronizar
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="removeProductFromNuvem('${p.nuvemshop_product_id}', '${escapeHtml(p.nome)}')" title="Retirar da Nuvemshop (o eGestor continua 100% intacto)">
                            🗑️ Retirar
                        </button>
                    </div>
                `;
            } else {
                statusHtml = `<span class="status-badge neutral"><span class="status-dot"></span> Não Cadastrado</span>`;
                actionHtml = `
                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                        <button class="btn btn-primary btn-sm" onclick="sendProductToNuvem('${p.codigo}', true)" title="Cadastrar e enviar este produto para a Nuvemshop">
                            ⚡ Enviar para Nuvem
                        </button>
                    </div>
                `;
            }

            return `
                <tr>
                    <td><strong>${p.codigo}</strong></td>
                    <td><span style="font-family: var(--font-mono); font-size: 12px; color: var(--text-muted);">${p.sku || '-'}</span></td>
                    <td><span style="font-family: var(--font-mono); font-size: 11px; color: var(--text-dim);">${p.barcode || '-'}</span></td>
                    <td><strong>${escapeHtml(p.nome)}</strong></td>
                    <td>${stockBadge}</td>
                    <td>${nuvemStockBadge}</td>
                    <td>${p.preco ? 'R$ ' + parseFloat(p.preco).toFixed(2) : '-'}</td>
                    <td>${statusHtml}</td>
                    <td style="text-align: right;">${actionHtml}</td>
                </tr>
            `;
        }).join('');

    } catch (err) {
        tableBody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: var(--danger); padding: 30px;">Erro de conexão ao consultar produtos do eGestor.</td></tr>';
    }
}

function resetProductsListFilters() {
    const s = document.getElementById('search-products-list');
    if (s) s.value = '';
    const st = document.getElementById('filter-status-nuvem');
    if (st) st.value = 'all';
    const sk = document.getElementById('filter-stock-level');
    if (sk) sk.value = 'all';
    loadProductsList(1);
}

// Sincroniza estoque de um produto avulso
async function syncSingleProduct(codigo) {
    if (!codigo) {
        showToast('Código de produto inválido', 'error');
        return;
    }

    showToast(`Sincronizando estoque do produto ${codigo}...`, 'info');
    try {
        const res = await fetch(`api.php?action=sync_product&codigo=${encodeURIComponent(codigo)}`, { method: 'POST' });
        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Produto sincronizado com sucesso!', 'success');
            loadProductsList(window.currentProductsPage || 1);
            loadStatus(true);
        } else {
            showToast('Falha: ' + (data.error || 'Erro desconhecido'), 'error');
        }
    } catch (err) {
        showToast('Erro na requisição de sincronização', 'error');
    }
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
            loadProductsList(window.currentProductsPage || 1);
            loadStatus(true);
        } else {
            showToast('Falha no envio: ' + (data.error || 'Erro desconhecido'), 'error');
        }
    } catch (err) {
        showToast('Erro de comunicação ao enviar produto', 'error');
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
            loadProductsList(window.currentProductsPage || 1);
            loadStatus(true);
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

// ========================================================
// CADASTRO DE PRODUTO NOS MOLDES DA NUVEMSHOP
// ========================================================
function initNewProductForm() {
    const chk = document.getElementById('chk-has-variants');
    if (chk) toggleHasVariants(chk.checked);
    const chk2 = document.getElementById('chk-enable-prop-2');
    if (chk2) toggleProp2(chk2.checked);
    generateVariantsTable();

    // Registra listeners diretos nos inputs da grade
    ['prop-1-values', 'prop-2-values', 'prop-1-name', 'prop-2-name', 'prod-price'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', generateVariantsTable);
            el.addEventListener('change', generateVariantsTable);
        }
    });
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
    const prop1Values = prop1Raw.split(/[,;\n]+/).map(s => s.trim()).filter(s => s !== '');

    const prop2Enabled = document.getElementById('chk-enable-prop-2')?.checked ?? true;
    const prop2Name = (document.getElementById('prop-2-name')?.value || 'Tamanho').trim();
    const prop2Raw = document.getElementById('prop-2-values')?.value || '';
    const prop2Values = prop2Enabled ? prop2Raw.split(/[,;\n]+/).map(s => s.trim()).filter(s => s !== '') : [];

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
        tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-dim); padding: 25px;">Nenhuma opção definida. Preencha os valores acima (ex: Verde, Rosa, Preto).</td></tr>`;
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
                <td style="font-weight: 600;">
                    <span style="display: inline-block; background: #f1f5f9; padding: 4px 10px; border-radius: 6px; color: var(--text-main);">
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
                    <input type="text" class="form-control variant-val-sku" value="${escapeHtml(skuVal)}" placeholder="Auto (ID)">
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

    // Imagens
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
            <strong>Processando Cadastro Atômico:</strong><br>
            1. Criando ${hasVariants ? payload.variants.length + ' variações individuais' : 'produto'} no eGestor...<br>
            2. Criando anúncio unificado com grade na Nuvemshop...<br>
            3. Registrando controle de estoque e SKUs...
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
            loadProductsList(1);
            loadStatus(true);
            loadLogs();

            setTimeout(() => {
                const goTab = confirm('Produto cadastrado com sucesso! Deseja visualizar na Lista de Produtos?');
                if (goTab) {
                    switchTab('tab-lista-produtos');
                }
            }, 400);

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

// ========================================================
// LOGS DO SISTEMA
// ========================================================
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

// ========================================================
// CONFIGURAÇÕES E WEBHOOKS
// ========================================================
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
            loadStatus();
        } else {
            showToast('Erro ao salvar: ' + (data.error || 'Desconhecido'), 'error');
        }
    } catch (err) {
        showToast('Erro de conexão ao salvar configurações', 'error');
    }
}

async function registerNuvemshopWebhooks() {
    const url = document.getElementById('nuvem-url-text').textContent.trim();
    if (url.includes('localhost') || url.includes('127.0.0.1')) {
        alert('Atenção: A Nuvemshop exige uma URL pública acessível pela internet com HTTPS.\n\nQuando subir para a Hostinger, clique neste botão para registrar os eventos de vendas automaticamente.');
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

async function submitChangePassword(e) {
    e.preventDefault();
    const curEl = document.getElementById('pwd-current');
    const newEl = document.getElementById('pwd-new');
    const btn = document.getElementById('btn-save-password');

    const currentPass = curEl ? curEl.value : '';
    const newPass = newEl ? newEl.value : '';

    if (!currentPass || !newPass) {
        showToast('Preencha a senha atual e a nova senha.', 'warning');
        return;
    }

    if (newPass.length < 4) {
        showToast('A nova senha deve ter no mínimo 4 caracteres.', 'warning');
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Salvando...';
    }

    try {
        const formData = new FormData();
        formData.append('current_password', currentPass);
        formData.append('new_password', newPass);

        const res = await fetch('api.php?action=change_password', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Senha alterada com sucesso!', 'success');
            if (curEl) curEl.value = '';
            if (newEl) newEl.value = '';
        } else {
            showToast(data.error || 'Erro ao alterar senha.', 'error');
        }
    } catch (err) {
        showToast('Erro de comunicação ao atualizar senha.', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = '🔒 Salvar Nova Senha';
        }
    }
}

