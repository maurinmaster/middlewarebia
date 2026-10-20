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
    'tab-pedidos': {
        title: 'Vendas & Emissão no eGestor (NF-e)',
        desc: 'Pedidos realizados na Nuvemshop com clientes cadastrados e vendas prontas para emissão de NF-e no eGestor'
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
    } else if (targetId === 'tab-pedidos') {
        loadOrdersList();
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
    window.selectedUnifyProducts = window.selectedUnifyProducts || new Map();
    const tableBody = document.getElementById('products-list-tbody');
    const summaryText = document.getElementById('products-list-summary');
    const pagTop = document.getElementById('pagination-top-btns');
    const pagBottom = document.getElementById('pagination-bottom-btns');
    const pagInfo = document.getElementById('pagination-bottom-info');

    if (!tableBody) return;

    tableBody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: var(--text-dim); padding: 30px;">Consultando produtos no eGestor...</td></tr>';
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
            tableBody.innerHTML = `<tr><td colspan="10" style="text-align: center; color: var(--danger); padding: 30px;">Erro ao consultar eGestor: ${escapeHtml(data.error || 'Falha')}</td></tr>`;
            if (summaryText) summaryText.textContent = 'Erro ao carregar catálogo';
            return;
        }

        const items = data.items || [];
        window.currentProductsList = items;
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
            tableBody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: var(--text-dim); padding: 35px;">Nenhum produto encontrado nesta página com os filtros selecionados.</td></tr>';
            if (typeof updateUnifySelectionUI === 'function') updateUnifySelectionUI();
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

            const isChecked = window.selectedUnifyProducts && window.selectedUnifyProducts.has(String(p.codigo));

            return `
                <tr>
                    <td style="text-align: center;">
                        <input type="checkbox" class="unify-checkbox" data-id="${p.codigo}" onchange="toggleUnifySelection('${p.codigo}')" ${isChecked ? 'checked' : ''} title="Selecionar para unificar em grade">
                    </td>
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

        if (typeof updateUnifySelectionUI === 'function') updateUnifySelectionUI();

    } catch (err) {
        tableBody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: var(--danger); padding: 30px;">Erro de conexão ao consultar produtos do eGestor.</td></tr>';
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

    // Coleta dados fiscais para cadastro no eGestor
    const ncmVal = (document.getElementById('prod-ncm')?.value || '').trim();
    const cestVal = (document.getElementById('prod-cest')?.value || '').trim();
    const origemVal = document.getElementById('prod-origem-fiscal')?.value ?? '0';
    const unTribVal = document.getElementById('prod-unidade-tributada')?.value || 'UN';
    const grupoTribVal = (document.getElementById('prod-grupo-tributos')?.value || '1').trim();
    const benefFiscalVal = (document.getElementById('prod-beneficio-fiscal')?.value || '').trim();
    const pesoLiqVal = document.getElementById('prod-peso-liquido')?.value;
    const pesoBrutoVal = document.getElementById('prod-peso-bruto')?.value;
    const anotacoesNfeVal = (document.getElementById('prod-anotacoes-nfe')?.value || '').trim();

    payload.fiscal = {
        ncm: ncmVal,
        cest: cestVal,
        origem_fiscal: origemVal,
        unidade_tributada: unTribVal,
        grupo_tributos: grupoTribVal,
        beneficio_fiscal: benefFiscalVal,
        peso_liquido: pesoLiqVal !== '' && pesoLiqVal !== undefined ? parseFloat(pesoLiqVal) : null,
        peso_bruto: pesoBrutoVal !== '' && pesoBrutoVal !== undefined ? parseFloat(pesoBrutoVal) : null,
        anotacoes_nfe: anotacoesNfeVal
    };

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

// ========================================================
// EXPORTAÇÃO PARA EXCEL (.XLSX)
// ========================================================
function openExportModal() {
    const modal = document.getElementById('export-excel-modal');
    if (!modal) return;

    const visibleItems = window.currentProductsList || [];
    const descEl = document.getElementById('export-visible-desc');
    if (descEl) {
        descEl.innerHTML = `Exporta os <strong>${visibleItems.length}</strong> produtos atualmente exibidos nesta página da tabela.`;
    }

    modal.classList.add('active');
}

function closeExportModal() {
    const modal = document.getElementById('export-excel-modal');
    if (modal) modal.classList.remove('active');
}

async function executeExcelExport() {
    const btn = document.getElementById('btn-do-export-excel');
    const selectedScope = document.querySelector('input[name="export-scope"]:checked')?.value || 'visible';

    const filterInput = document.getElementById('search-products-list');
    const filter = filterInput ? filterInput.value.trim() : '';
    const statusEl = document.getElementById('filter-status-nuvem');
    const status = statusEl ? statusEl.value : 'all';
    const stockEl = document.getElementById('filter-stock-level');
    const stock = stockEl ? stockEl.value : 'all';

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '⏳ Gerando Planilha...';
    }

    try {
        let productsToExport = [];

        if (selectedScope === 'visible') {
            productsToExport = window.currentProductsList || [];
            if (productsToExport.length === 0) {
                showToast('Nenhum produto visível na página para exportar.', 'warning');
                return;
            }
        } else {
            showToast('Consultando catálogo do eGestor (pode levar alguns segundos)...', 'info');
            const params = new URLSearchParams({
                action: 'export_egestor_full_catalog',
                filter: filter,
                status: status,
                stock: stock
            });
            const res = await fetch(`api.php?${params.toString()}`);
            const data = await res.json();
            if (!data.success) {
                throw new Error(data.error || 'Falha ao consultar eGestor via API');
            }
            productsToExport = data.items || [];
        }

        if (productsToExport.length === 0) {
            showToast('Nenhum produto encontrado para exportar com os filtros atuais.', 'warning');
            return;
        }

        // Formata as linhas para o Excel com nomes claros em português e dados fiscais completos
        const excelRows = productsToExport.map(p => {
            const estoquePDV = Number(p.estoque ?? 0);
            const estoqueNuvem = p.in_nuvemshop && p.stock_nuvemshop !== null && p.stock_nuvemshop !== undefined
                ? Number(p.stock_nuvemshop)
                : (p.in_nuvemshop ? 0 : 'Não Cadastrado');

            return {
                'Código eGestor': p.codigo || '',
                'SKU': p.sku || '',
                'Código de Barras (EAN/GTIN)': p.barcode || '',
                'Descrição / Nome do Produto': p.nome || '',
                'NCM': p.ncm || '',
                'CEST': p.cest || '',
                'Origem Fiscal': p.origem_fiscal_desc || (p.origem_fiscal !== undefined && p.origem_fiscal !== '' ? String(p.origem_fiscal) : '0 - Nacional'),
                'Unidade Tributada': p.unidade_tributada || 'UN',
                'GTIN Tributável': p.gtin_tributavel || p.barcode || '',
                'Preço de Custo (R$)': Number(p.preco_custo || 0),
                'Preço de Venda (R$)': Number(p.preco || 0),
                'Estoque PDV (eGestor)': estoquePDV,
                'Estoque Nuvemshop': estoqueNuvem,
                'Status Nuvemshop': p.in_nuvemshop ? 'Cadastrado na Nuvemshop' : 'Não Enviado',
                'Regra / Grupo Tributos': p.grupo_tributos || '',
                'Benefício Fiscal': p.beneficio_fiscal || '',
                'Exceção IPI': p.excecao_ipi || '',
                'Peso Líquido (kg)': Number(p.peso_liquido || 0),
                'Peso Bruto (kg)': Number(p.peso_bruto || 0),
                'Anotações Fiscais (NF-e)': p.anotacoes_nfe || '',
                'ID Produto Nuvemshop': p.nuvemshop_product_id || '',
                'ID Variação Nuvemshop': p.nuvemshop_variant_id || ''
            };
        });

        const today = new Date().toISOString().slice(0, 10);
        const fileName = `produtos_middleware_bia_${today}.xlsx`;

        // Se SheetJS (XLSX) estiver disponível
        if (typeof XLSX !== 'undefined') {
            const worksheet = XLSX.utils.json_to_sheet(excelRows);

            // Ajusta largura automática das colunas
            const keys = Object.keys(excelRows[0] || {});
            worksheet['!cols'] = keys.map(key => {
                let maxLen = key.length;
                for (const row of excelRows) {
                    const val = row[key];
                    if (val !== null && val !== undefined) {
                        maxLen = Math.max(maxLen, String(val).length);
                    }
                }
                return { wch: Math.min(Math.max(maxLen + 3, 10), 65) };
            });

            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, worksheet, 'Produtos');
            XLSX.writeFile(workbook, fileName);
        } else {
            // Fallback para CSV com UTF-8 BOM compatível direto com Microsoft Excel
            exportToCsvFallback(excelRows, `produtos_middleware_bia_${today}.csv`);
        }

        showToast(`Planilha Excel gerada com sucesso! (${productsToExport.length} produtos)`, 'success');
        closeExportModal();

    } catch (err) {
        console.error('Erro na exportação Excel:', err);
        showToast('Erro ao exportar planilha: ' + (err.message || 'Falha'), 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '📥 Baixar Planilha Excel (.xlsx)';
        }
    }
}

function exportToCsvFallback(rows, filename) {
    if (!rows.length) return;
    const headers = Object.keys(rows[0]);
    const csvContent = '\uFEFF' + [
        headers.join(';'),
        ...rows.map(r => headers.map(h => {
            const val = r[h] === null || r[h] === undefined ? '' : String(r[h]);
            return `"${val.replace(/"/g, '""')}"`;
        }).join(';'))
    ].join('\r\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// ========================================================
// VENDAS & EMISSÃO DE NF-E NO EGESTOR (NUVEMSHOP ⇄ EGESTOR)
// ========================================================
async function loadOrdersList() {
    const tbody = document.getElementById('orders-list-tbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: var(--text-dim); padding: 30px;">Consultando vendas e pedidos sincronizados...</td></tr>';

    try {
        const res = await fetch('api.php?action=orders');
        const data = await res.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; color: var(--danger); padding: 30px;">Erro ao carregar pedidos: ${escapeHtml(data.error || 'Falha')}</td></tr>`;
            return;
        }

        const orders = data.orders || [];
        if (orders.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 45px 20px;">
                        <div style="font-size: 32px; margin-bottom: 10px;">🧾</div>
                        <strong style="font-size: 14px; color: var(--text-main);">Nenhuma venda registrada ainda</strong>
                        <p style="font-size: 12px; color: var(--text-dim); margin-top: 6px; max-width: 500px; margin-left: auto; margin-right: auto; line-height: 1.5;">
                            Quando um cliente comprar na Nuvemshop, o pedido aparecerá aqui automaticamente. O middleware cadastra o cliente (com CPF/CNPJ e endereço completo) e lança a Venda no eGestor pronta para emissão de NF-e.
                        </p>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = orders.map(o => {
            const cpfDisplay = o.customer_cpf_cnpj ? formatCpfCnpj(o.customer_cpf_cnpj) : '<span style="color: var(--text-dim);">Não informado</span>';
            const totalVal = parseFloat(o.order_total || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            
            let statusBadge = '';
            if (o.status === 'sale_registered') {
                statusBadge = '<span class="status-badge online"><span class="status-dot"></span> Venda no eGestor (Pronta para NF-e)</span>';
            } else if (o.status === 'error') {
                statusBadge = `<span class="status-badge offline" title="${escapeHtml(o.details || '')}"><span class="status-dot"></span> Erro no eGestor</span>`;
            } else {
                statusBadge = '<span class="status-badge warning"><span class="status-dot"></span> Pendente</span>';
            }

            const dt = o.created_at ? new Date(o.created_at).toLocaleString('pt-BR') : '-';

            return `
                <tr>
                    <td><strong>#${escapeHtml(o.nuvemshop_order_id)}</strong></td>
                    <td>
                        <div style="font-weight: 600; color: var(--text-main);">${escapeHtml(o.customer_name || 'Cliente')}</div>
                        <div style="font-size: 11px; color: var(--text-dim);">${escapeHtml(o.customer_email || '')}</div>
                    </td>
                    <td><span style="font-family: var(--font-mono); font-size: 12px;">${cpfDisplay}</span></td>
                    <td><strong>${totalVal}</strong></td>
                    <td>
                        ${o.egestor_contact_id 
                            ? `<span style="font-family: var(--font-mono); font-size: 12px; color: #2563eb; background: #eff6ff; padding: 2px 6px; border-radius: 4px;">#${o.egestor_contact_id}</span>` 
                            : '<span style="color: var(--text-dim);">-</span>'}
                    </td>
                    <td>
                        ${o.egestor_sale_id 
                            ? `<span style="font-family: var(--font-mono); font-size: 12px; font-weight: 700; color: #059669; background: #ecfdf5; padding: 2px 6px; border-radius: 4px;">#${o.egestor_sale_id}</span>` 
                            : '<span style="color: var(--text-dim);">-</span>'}
                    </td>
                    <td>${statusBadge}</td>
                    <td style="font-size: 12px; color: var(--text-muted);">${dt}</td>
                    <td style="text-align: right;">
                        <button class="btn btn-secondary btn-sm" onclick="reSyncOrder('${escapeHtml(o.nuvemshop_order_id)}')" title="Reenviar dados e reprocessar venda no eGestor">
                            🔄 Reenviar
                        </button>
                    </td>
                </tr>
            `;
        }).join('');

    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: var(--danger); padding: 30px;">Falha de comunicação ao carregar pedidos.</td></tr>';
    }
}

function formatCpfCnpj(v) {
    v = (v || '').replace(/\D/g, '');
    if (v.length === 11) {
        return v.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    } else if (v.length === 14) {
        return v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    }
    return v;
}

function openManualOrderSyncModal() {
    const m = document.getElementById('manual-order-modal');
    if (m) {
        const inp = document.getElementById('manual-order-id');
        if (inp) inp.value = '';
        m.classList.add('active');
        if (inp) inp.focus();
    }
}

function closeManualOrderSyncModal() {
    const m = document.getElementById('manual-order-modal');
    if (m) m.classList.remove('active');
}

async function submitManualOrderSync(e) {
    e.preventDefault();
    const inp = document.getElementById('manual-order-id');
    const orderId = inp ? inp.value.trim() : '';
    if (!orderId) {
        showToast('Informe o ID do pedido na Nuvemshop.', 'warning');
        return;
    }

    const btn = document.getElementById('btn-manual-order-submit');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Enviando ao eGestor...';
    }

    showToast(`Processando pedido #${orderId} no eGestor...`, 'info');
    try {
        const formData = new FormData();
        formData.append('order_id', orderId);

        const res = await fetch('api.php?action=sync_order', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Venda lançada no eGestor com sucesso!', 'success');
            closeManualOrderSyncModal();
            loadOrdersList();
            loadLogs();
        } else {
            showToast('Erro ao sincronizar pedido: ' + (data.error || 'Falha'), 'error');
        }
    } catch (err) {
        showToast('Erro de comunicação ao enviar pedido.', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = '🚀 Enviar para eGestor';
        }
    }
}

async function reSyncOrder(orderId) {
    if (!confirm(`Deseja reprocessar o pedido #${orderId} no eGestor?`)) return;
    
    showToast(`Reprocessando pedido #${orderId} no eGestor...`, 'info');
    try {
        const formData = new FormData();
        formData.append('order_id', orderId);

        const res = await fetch('api.php?action=sync_order', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Pedido sincronizado com sucesso no eGestor!', 'success');
            loadOrdersList();
            loadLogs();
        } else {
            showToast('Erro: ' + (data.error || 'Falha ao sincronizar'), 'error');
        }
    } catch (err) {
        showToast('Erro de conexão ao reenviar pedido.', 'error');
    }
}

// ========================================================
// UNIFICAÇÃO DE PRODUTOS DO EGESTOR EM GRADE NUVEMSHOP
// ========================================================
window.selectedUnifyProducts = window.selectedUnifyProducts || new Map();

function toggleUnifySelection(codigo) {
    const sCodigo = String(codigo);
    if (window.selectedUnifyProducts.has(sCodigo)) {
        window.selectedUnifyProducts.delete(sCodigo);
    } else {
        const prod = (window.currentProductsList || []).find(p => String(p.codigo) === sCodigo);
        if (prod) {
            window.selectedUnifyProducts.set(sCodigo, prod);
        }
    }
    updateUnifySelectionUI();
}

function toggleSelectAllUnify(masterCheckbox) {
    const isChecked = masterCheckbox.checked;
    const pageItems = window.currentProductsList || [];

    for (const p of pageItems) {
        const sCodigo = String(p.codigo);
        if (isChecked) {
            window.selectedUnifyProducts.set(sCodigo, p);
        } else {
            window.selectedUnifyProducts.delete(sCodigo);
        }
    }

    document.querySelectorAll('.unify-checkbox').forEach(cb => {
        cb.checked = isChecked;
    });

    updateUnifySelectionUI();
}

function updateUnifySelectionUI() {
    const count = window.selectedUnifyProducts.size;
    const countEl = document.getElementById('unify-selected-count');
    const btn = document.getElementById('btn-open-unify-modal');
    const masterCb = document.getElementById('select-all-unify');

    if (countEl) countEl.textContent = count;
    if (btn) {
        btn.disabled = count < 2;
        if (count >= 2) {
            btn.style.background = '#4f46e5';
            btn.style.color = '#ffffff';
            btn.style.borderColor = '#4f46e5';
        } else {
            btn.style.background = '';
            btn.style.color = '#6366f1';
            btn.style.borderColor = '#6366f1';
        }
    }

    if (masterCb) {
        const pageItems = window.currentProductsList || [];
        if (pageItems.length === 0) {
            masterCb.checked = false;
            masterCb.indeterminate = false;
        } else {
            const pageSelected = pageItems.filter(p => window.selectedUnifyProducts.has(String(p.codigo))).length;
            if (pageSelected === pageItems.length) {
                masterCb.checked = true;
                masterCb.indeterminate = false;
            } else if (pageSelected > 0) {
                masterCb.checked = false;
                masterCb.indeterminate = true;
            } else {
                masterCb.checked = false;
                masterCb.indeterminate = false;
            }
        }
    }
}

// Auxiliar inteligente para deduzir nome base comum e cores/diferenças
function deduceUnifyBaseNameAndColors(products) {
    if (!products || products.length === 0) return { baseName: '', colors: [] };
    const names = products.map(p => (p.nome || '').trim());

    // Lista de cores conhecidas comuns em moda/vestuário
    const commonColors = [
        'preto', 'preta', 'branco', 'branca', 'off-white', 'off white', 'offwhite',
        'azul marinho', 'azul royal', 'azul claro', 'azul celeste', 'azul',
        'verde militar', 'verde musgo', 'verde bandeira', 'verde menta', 'verde lima', 'verde',
        'rosa bebe', 'rosa bebê', 'rosa chiclete', 'rosa pink', 'pink', 'rosa',
        'vermelho', 'vermelha', 'vinho', 'bordo', 'bordô', 'marsala',
        'amarelo', 'amarela', 'mostarda', 'laranja', 'terracota', 'coral',
        'bege', 'nude', 'marrom', 'caramelo', 'cacau', 'fendi',
        'cinza mescla', 'cinza chumbo', 'cinza claro', 'cinza',
        'lilas', 'lilás', 'roxo', 'roxa', 'lavanda', 'dourado', 'prata'
    ];

    // Tenta encontrar maior prefixo comum
    let commonPrefix = names[0];
    for (let i = 1; i < names.length; i++) {
        while (!names[i].toLowerCase().startsWith(commonPrefix.toLowerCase()) && commonPrefix.length > 0) {
            commonPrefix = commonPrefix.slice(0, -1);
        }
    }
    // Remove traços ou espaços no final
    commonPrefix = commonPrefix.replace(/[\s\-_/]+$/, '').trim();

    // Se o prefixo for muito curto (< 3 letras), usa o nome do primeiro
    let baseName = commonPrefix;
    if (baseName.length < 3) {
        baseName = names[0];
    }

    // Para cada produto, extrai a cor
    const colors = products.map(p => {
        const origName = (p.nome || '').trim();
        const lowerName = origName.toLowerCase();

        // 1. Procura na lista de cores
        for (const c of commonColors) {
            const regex = new RegExp(`\\b${c}\\b`, 'i');
            if (regex.test(lowerName)) {
                return c.charAt(0).toUpperCase() + c.slice(1);
            }
        }

        // 2. Se tinha prefixo comum, pega o que sobrou
        if (commonPrefix && commonPrefix.length >= 3 && lowerName.startsWith(commonPrefix.toLowerCase())) {
            let rest = origName.substring(commonPrefix.length).replace(/^[\s\-_/]+/, '').trim();
            if (rest) return rest;
        }

        // 3. Fallback: última palavra do nome
        const parts = origName.split(/[\s\-_/]+/);
        return parts.length > 1 ? parts[parts.length - 1] : origName;
    });

    return { baseName, colors };
}

function openUnifyModal() {
    const modal = document.getElementById('unify-modal');
    if (!modal) return;

    const count = window.selectedUnifyProducts.size;
    if (count < 2) {
        showToast('Selecione pelo menos 2 produtos do eGestor na tabela para unificar.', 'warning');
        return;
    }

    const products = Array.from(window.selectedUnifyProducts.values());
    const deduction = deduceUnifyBaseNameAndColors(products);

    const nameInput = document.getElementById('unify-product-name');
    if (nameInput) nameInput.value = deduction.baseName || products[0].nome || '';

    const descInput = document.getElementById('unify-product-desc');
    if (descInput) descInput.value = `Produto com grade de variações unificadas a partir do catálogo do eGestor.`;

    const countEl = document.getElementById('unify-modal-items-count');
    if (countEl) countEl.textContent = products.length;

    const defaultPriceInput = document.getElementById('unify-default-price');
    if (defaultPriceInput) defaultPriceInput.value = '';

    const defaultAttr2 = document.getElementById('unify-attr2-default')?.value || 'Único (P/M/G)';
    const enableAttr2 = document.getElementById('unify-enable-attr2')?.checked !== false;

    // Renderiza linhas da tabela de variações no modal
    const tbody = document.getElementById('unify-modal-tbody');
    if (tbody) {
        tbody.innerHTML = products.map((p, idx) => {
            const guessedColor = deduction.colors[idx] || `Cor ${idx + 1}`;
            const skuVal = p.sku && p.sku !== '-' ? p.sku : p.codigo;
            const priceVal = p.preco ? parseFloat(p.preco).toFixed(2) : '0.00';
            const stockVal = parseFloat(p.estoque || 0);

            return `
                <tr id="unify-row-${p.codigo}" data-egestor-id="${p.codigo}">
                    <td><strong>${p.codigo}</strong></td>
                    <td title="${escapeHtml(p.nome)}">
                        <span style="display: block; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 500;">
                            ${escapeHtml(p.nome)}
                        </span>
                    </td>
                    <td>
                        <input type="text" class="form-control unify-item-attr1" value="${escapeHtml(guessedColor)}" required style="height: 30px; font-size: 12px;" placeholder="Ex: Rosa">
                    </td>
                    <td class="unify-col-attr2" style="${enableAttr2 ? '' : 'display: none;'}">
                        <input type="text" class="form-control unify-item-attr2" value="${escapeHtml(defaultAttr2)}" style="height: 30px; font-size: 12px;" placeholder="Ex: Único">
                    </td>
                    <td>
                        <span class="stock-tag ${stockVal > 0 ? 'in-stock' : 'out-of-stock'}">${stockVal} un</span>
                    </td>
                    <td>
                        <input type="number" step="0.01" class="form-control unify-item-price" value="${priceVal}" style="height: 30px; font-size: 12px; width: 95px;">
                    </td>
                    <td>
                        <input type="text" class="form-control unify-item-sku" value="${escapeHtml(skuVal)}" style="height: 30px; font-size: 11px; font-family: var(--font-mono); width: 90px;" title="SKU que vincula este produto ao eGestor">
                    </td>
                    <td style="text-align: center;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="removeUnifyModalItem('${p.codigo}')" title="Remover da unificação" style="padding: 2px 6px; color: var(--danger); border-color: transparent;">
                            ✕
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    const statusBox = document.getElementById('unify-status-box');
    if (statusBox) statusBox.style.display = 'none';

    updateModalAttrHeaders();
    modal.classList.add('active');
}

function closeUnifyModal() {
    const modal = document.getElementById('unify-modal');
    if (modal) modal.classList.remove('active');
}

function toggleUnifyAttr2(enabled) {
    const group = document.getElementById('unify-attr2-group');
    const th2 = document.getElementById('th-unify-attr2');
    const cols = document.querySelectorAll('.unify-col-attr2');

    if (group) group.style.display = enabled ? 'block' : 'none';
    if (th2) th2.style.display = enabled ? '' : 'none';
    cols.forEach(td => td.style.display = enabled ? '' : 'none');
}

function updateModalAttrHeaders() {
    const attr1 = document.getElementById('unify-attr1-name')?.value.trim() || 'Cor';
    const attr2 = document.getElementById('unify-attr2-name')?.value.trim() || 'Tamanho';

    const th1 = document.getElementById('th-unify-attr1');
    const th2 = document.getElementById('th-unify-attr2');

    if (th1) th1.textContent = attr1 + ' *';
    if (th2) th2.textContent = attr2 + ' *';
}

function applyDefaultAttr2ToAll(val) {
    document.querySelectorAll('.unify-item-attr2').forEach(input => {
        input.value = val;
    });
}

function applyDefaultPriceToAll(val) {
    if (val === '' || isNaN(val)) return;
    const num = parseFloat(val).toFixed(2);
    document.querySelectorAll('.unify-item-price').forEach(input => {
        input.value = num;
    });
}

function removeUnifyModalItem(egestorId) {
    window.selectedUnifyProducts.delete(String(egestorId));
    const row = document.getElementById(`unify-row-${egestorId}`);
    if (row) row.remove();

    const cb = document.querySelector(`.unify-checkbox[data-id="${egestorId}"]`);
    if (cb) cb.checked = false;

    updateUnifySelectionUI();

    const count = window.selectedUnifyProducts.size;
    const countEl = document.getElementById('unify-modal-items-count');
    if (countEl) countEl.textContent = count;

    if (count < 2) {
        showToast('Restou menos de 2 produtos. O modal de unificação requer ao menos 2 itens.', 'warning');
        closeUnifyModal();
    }
}

async function submitUnifyProducts() {
    const nameInput = document.getElementById('unify-product-name');
    const name = nameInput ? nameInput.value.trim() : '';

    if (!name) {
        showToast('Informe o Nome Principal do Produto para a Nuvemshop.', 'warning');
        if (nameInput) nameInput.focus();
        return;
    }

    const descInput = document.getElementById('unify-product-desc');
    const description = descInput ? descInput.value.trim() : '';

    const attr1Name = (document.getElementById('unify-attr1-name')?.value || 'Cor').trim();
    const enableAttr2 = document.getElementById('unify-enable-attr2')?.checked !== false;
    const attr2Name = (document.getElementById('unify-attr2-name')?.value || 'Tamanho').trim();

    const attributes = [attr1Name];
    if (enableAttr2 && attr2Name) {
        attributes.push(attr2Name);
    }

    const rows = document.querySelectorAll('#unify-modal-tbody tr');
    if (rows.length < 2) {
        showToast('A unificação requer pelo menos 2 produtos do eGestor.', 'warning');
        return;
    }

    const variants = [];
    for (const r of rows) {
        const egestorId = r.getAttribute('data-egestor-id');
        const attr1Val = (r.querySelector('.unify-item-attr1')?.value || '').trim();
        const attr2Val = (r.querySelector('.unify-item-attr2')?.value || '').trim();
        const priceVal = parseFloat(r.querySelector('.unify-item-price')?.value || 0);
        const skuVal = (r.querySelector('.unify-item-sku')?.value || '').trim() || egestorId;

        if (!attr1Val) {
            showToast(`Preencha o valor de ${attr1Name} para o produto eGestor #${egestorId}`, 'warning');
            return;
        }

        const values = [attr1Val];
        if (enableAttr2) {
            if (!attr2Val) {
                showToast(`Preencha o valor de ${attr2Name} para o produto eGestor #${egestorId}`, 'warning');
                return;
            }
            values.push(attr2Val);
        }

        variants.push({
            egestor_id: egestorId,
            sku: skuVal,
            price: priceVal,
            values: values
        });
    }

    const payload = {
        name: name,
        description: description,
        attributes: attributes,
        variants: variants
    };

    const btn = document.getElementById('btn-submit-unify');
    const statusBox = document.getElementById('unify-status-box');

    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Criando Anúncio com Grade na Nuvemshop...';

    if (statusBox) {
        statusBox.style.display = 'block';
        statusBox.style.background = '#eff6ff';
        statusBox.style.border = '1px solid #bfdbfe';
        statusBox.style.color = '#1e40af';
        statusBox.innerHTML = `
            <strong>Enviando para Nuvemshop:</strong><br>
            - Montando grade de ${variants.length} variações vinculadas aos IDs do eGestor...<br>
            - Aguarde a resposta da API da Nuvemshop...
        `;
    }

    try {
        const res = await fetch('api.php?action=create_unified_product', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Produto unificado com sucesso na Nuvemshop!', 'success');
            if (statusBox) {
                statusBox.style.background = '#f0fdf4';
                statusBox.style.border = '1px solid #bbf7d0';
                statusBox.style.color = '#166534';
                statusBox.innerHTML = `✅ <strong>Sucesso!</strong> Produto criado na Nuvemshop com grade unificada (#${data.nuvemshop_product_id}).`;
            }

            // Limpa seleções
            window.selectedUnifyProducts.clear();
            updateUnifySelectionUI();

            setTimeout(() => {
                closeUnifyModal();
                loadProductsList(window.currentProductsPage || 1);
                loadLogs();
            }, 1800);

        } else {
            showToast('Erro ao unificar produtos: ' + (data.error || 'Falha'), 'error');
            if (statusBox) {
                statusBox.style.background = '#fef2f2';
                statusBox.style.border = '1px solid #fecaca';
                statusBox.style.color = '#991b1b';
                statusBox.innerHTML = `❌ <strong>Erro:</strong> ${escapeHtml(data.error || 'Falha ao processar')}`;
            }
        }
    } catch (err) {
        showToast('Erro de comunicação ao enviar dados para a API.', 'error');
        if (statusBox) {
            statusBox.style.background = '#fef2f2';
            statusBox.style.border = '1px solid #fecaca';
            statusBox.style.color = '#991b1b';
            statusBox.innerHTML = `❌ <strong>Erro de conexão:</strong> Falha ao se comunicar com o servidor.`;
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}



