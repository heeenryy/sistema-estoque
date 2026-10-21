'use strict';

/**
 * Sistema de Estoque - Frontend JS
 * Fluxo de leitura de código de barras: o leitor USB age como teclado,
 * digitando o código muito rápido e enviando Enter. Detectamos isso
 * pelo intervalo entre teclas e processamos automaticamente.
 */

const API = {
    auth: 'api/auth.php',
    items: 'api/items.php',
    movements: 'api/movements.php',
    dashboard: 'api/dashboard.php',
    users: 'api/users.php',
    account: 'api/account.php',
    reports: 'api/reports.php',
};

const state = {
    user: null,
    view: 'dashboard',
    itemsPage: 1,
    itemsPerPage: 30,
    historyOffset: 0,
    historyLimit: 50,
    searchTimer: null,
    entryCart: new Map(),
    exitCart: new Map(),
    labels: [],
    reportMode: 'movements', // movements | by_item | low_stock
};

/* ================== Utilidades ================== */

function $(sel, root = document) { return root.querySelector(sel); }
function $$(sel, root = document) { return Array.from(root.querySelectorAll(sel)); }

function fmtNum(n) {
    const v = parseFloat(n) || 0;
    return v.toLocaleString('pt-BR');
}

function fmtMoney(n) {
    const v = parseFloat(n) || 0;
    return v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function fmtDateTime(str) {
    if (!str) return '';
    const d = new Date(str.replace(' ', 'T'));
    return d.toLocaleString('pt-BR');
}

function fmtDate(str) {
    if (!str) return '';
    const d = new Date(str.replace(' ', 'T'));
    return d.toLocaleDateString('pt-BR');
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

function showToast(msg, type = '') {
    const t = $('#toast');
    t.textContent = msg;
    t.className = 'toast ' + type;
    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(() => t.classList.add('hidden'), 3500);
}

async function apiFetch(url, options = {}) {
    const opts = { headers: {}, ...options };
    if (opts.body && typeof opts.body !== 'string') {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(opts.body);
    }
    const res = await fetch(url, opts);
    let data = null;
    try { data = await res.json(); } catch { /* noop */ }
    if (res.status === 401) {
        // Sessão expirada/inválida -> volta ao login
        showLogin();
        throw new Error((data && data.message) || 'Sessão expirada. Faça login novamente.');
    }
    if (!res.ok || (data && data.ok === false)) {
        throw new Error((data && data.message) || `Erro ${res.status}`);
    }
    return data;
}

function setFeedback(el, html, cls) {
    el.innerHTML = html;
    el.className = 'feedback ' + cls;
}

function userCan(perm) {
    if (!state.user) return false;
    if (state.user.role === 'admin') return true;
    const perms = {
        'dashboard.view': true,
        'items.view': true,
        'items.create': true,
        'items.edit': true,
        'movement.in': true,
        'movement.out': true,
        'history.view': true,
        'reports.view': true,
        'labels.create': true,
        'account.manage': true,
    };
    return !!perms[perm];
}

/* ================== Autenticação / Login ================== */

function showLogin() {
    state.user = null;
    $('#login-screen').classList.remove('hidden');
    $('#app-shell').classList.add('hidden');
    setTimeout(() => $('#login-identifier').focus(), 50);
}

function showApp() {
    $('#login-screen').classList.add('hidden');
    $('#app-shell').classList.remove('hidden');
    renderUserChip();
    applyPermissions();
    switchView('dashboard');
}

function renderUserChip() {
    if (!state.user) return;
    const name = state.user.name || state.user.email;
    $('#user-name').textContent = name;
    $('#user-role').textContent = state.user.role === 'admin' ? 'Administrador' : 'Operador';
    $('#user-avatar').textContent = name.trim().charAt(0).toUpperCase() || '?';
}

function applyPermissions() {
    // Abas: esconder as que o perfil não pode ver
    $$('#main-tabs .tab[data-perm]').forEach(tab => {
        tab.style.display = userCan(tab.dataset.perm) ? '' : 'none';
    });
    $$('[data-perm]').forEach(el => {
        if (el.closest('#main-tabs')) return;
        el.style.display = userCan(el.dataset.perm) ? '' : 'none';
    });
}

async function checkSession() {
    try {
        const data = await apiFetch(API.auth);
        if (data.user) {
            state.user = data.user;
            showApp();
        } else {
            showLogin();
        }
    } catch (e) {
        showLogin();
    }
}

async function doLogin(identifier, password) {
    try {
        const data = await apiFetch(API.auth, { method: 'POST', body: { identifier, password } });
        state.user = data.user;
        showToast(`Bem-vindo(a), ${data.user.name || data.user.email}!`, 'success');
        showApp();
    } catch (e) {
        setFeedback($('#login-error'), e.message, 'err');
        $('#login-error').classList.remove('hidden');
    }
}

async function doLogout() {
    try { await apiFetch(API.auth, { method: 'DELETE' }); } catch { /* noop */ }
    showLogin();
}

/* ================== Navegação ================== */

function switchView(view) {
    // Não permitir navegar para telas sem permissão
    const permMap = {
        items: 'items.view', entry: 'movement.in', exit: 'movement.out',
        history: 'history.view', reports: 'reports.view',
        labels: 'labels.create', users: 'users.manage',
    };
    if (permMap[view] && !userCan(permMap[view])) {
        showToast('Acesso negado.', 'error');
        return;
    }

    state.view = view;
    $$('#main-tabs .tab').forEach(t => t.classList.toggle('active', t.dataset.view === view));
    $$('.view').forEach(v => v.classList.toggle('active', v.id === 'view-' + view));

    if (view === 'dashboard') loadDashboard();
    if (view === 'items') { loadItems(); loadCategories(); }
    if (view === 'entry') setTimeout(() => $('#entry-scan').focus(), 50);
    if (view === 'exit') setTimeout(() => $('#exit-scan').focus(), 50);
    if (view === 'history') loadHistory();
    if (view === 'reports') loadReports();
    if (view === 'users') loadUsers();
    if (view === 'account') loadAccount();
    if (view === 'labels') setTimeout(() => $('#label-code').focus(), 50);
}

/* ================== Leitor de código de barras ================== */

function setupScanner(inputId, onScan) {
    const input = $(inputId);
    if (!input) return;

    let lastKeyTime = 0;
    let isScanning = false;

    input.addEventListener('keydown', (e) => {
        const now = Date.now();
        const delta = now - lastKeyTime;
        lastKeyTime = now;

        if (delta > 0 && delta < 50) {
            isScanning = true;
        }

        if (e.key === 'Enter') {
            e.preventDefault();
            const code = input.value.trim();
            if (code) {
                onScan(code, isScanning);
                input.value = '';
                setTimeout(() => input.focus(), 10);
            }
        }
    });

    input.addEventListener('keypress', () => { isScanning = false; });
    input.addEventListener('focus', () => { isScanning = false; });
}

/* ================== Dashboard ================== */

async function loadDashboard() {
    try {
        const data = await apiFetch(API.dashboard);
        const s = data.stats;

        $('#stat-cards').innerHTML = `
            <div class="card"><div class="label">Itens cadastrados</div><div class="value">${fmtNum(s.total_items)}</div></div>
            <div class="card"><div class="label">Unidades em estoque</div><div class="value">${fmtNum(s.total_units)}</div></div>
            <div class="card ${s.low_stock > 0 ? 'danger' : ''}"><div class="label">Estoque baixo</div><div class="value">${fmtNum(s.low_stock)}</div></div>
            <div class="card"><div class="label">Valor em estoque</div><div class="value">${fmtMoney(s.total_value)}</div></div>
            <div class="card success"><div class="label">Entradas hoje</div><div class="value">+${fmtNum(s.today_in)}</div></div>
            <div class="card warning"><div class="label">Saídas hoje</div><div class="value">-${fmtNum(s.today_out)}</div></div>
        `;

        if (data.low_items.length) {
            $('#low-items').innerHTML = data.low_items.map(i => `
                <div class="list-row">
                    <span class="badge ${i.quantity <= 0 ? 'empty' : 'low'}">${fmtNum(i.quantity)} ${escapeHtml(i.unit)}</span>
                    <strong>${escapeHtml(i.name)}</strong>
                    <small>mín. ${fmtNum(i.min_stock)}</small>
                </div>`).join('');
        } else {
            $('#low-items').innerHTML = '<div class="empty">Nenhum item com estoque baixo.</div>';
        }

        if (data.recent_movements.length) {
            $('#recent-movements').innerHTML = data.recent_movements.map(m => `
                <div class="list-row">
                    <span class="badge ${m.type}">${m.type === 'in' ? 'ENTRADA' : m.type === 'out' ? 'SAÍDA' : 'AJUSTE'}</span>
                    <strong>${escapeHtml(m.item_name)}</strong>
                    <small>${m.type === 'in' ? '+' : '-'}${fmtNum(m.quantity)} · ${escapeHtml(m.user_name || '')} · ${fmtDateTime(m.created_at)}</small>
                </div>`).join('');
        } else {
            $('#recent-movements').innerHTML = '<div class="empty">Sem movimentações ainda.</div>';
        }
    } catch (e) {
        showToast(e.message, 'error');
    }
}

/* ================== Itens ================== */

async function loadItems() {
    const search = $('#item-search').value.trim();
    const category = $('#item-category-filter').value;
    const low = $('#item-low-filter').checked ? '1' : '';

    const params = new URLSearchParams({
        page: state.itemsPage,
        per_page: state.itemsPerPage,
        search, category, low,
    });

    try {
        const data = await apiFetch(API.items + '?' + params.toString());
        const tbody = $('#items-table tbody');

        if (!data.items.length) {
            tbody.innerHTML = `<tr><td colspan="9"><div class="empty">Nenhum item encontrado.</div></td></tr>`;
            $('#items-pagination').innerHTML = '';
            return;
        }

        const canEdit = userCan('items.edit');
        const canDelete = userCan('items.delete');

        tbody.innerHTML = data.items.map(i => {
            const lowStatus = parseFloat(i.quantity) <= parseFloat(i.min_stock);
            const actions = `
                ${canEdit ? `<button class="btn-icon edit" data-action="edit" data-id="${i.id}" title="Editar">✏️</button>` : ''}
                ${canDelete ? `<button class="btn-icon" data-action="delete" data-id="${i.id}" title="Excluir">🗑️</button>` : ''}
                <button class="btn-icon" data-action="label" data-id="${i.id}" title="Etiqueta">🏷️</button>`;
            return `
            <tr>
                <td><span class="badge ok">${escapeHtml(i.code)}</span></td>
                <td><strong>${escapeHtml(i.name)}</strong></td>
                <td>${escapeHtml(i.category || '—')}</td>
                <td>${escapeHtml(i.location || '—')}</td>
                <td class="num">${fmtNum(i.quantity)} ${escapeHtml(i.unit)}</td>
                <td class="num">${fmtNum(i.min_stock)}</td>
                <td class="num">${fmtMoney(i.price)}</td>
                <td class="num">${fmtMoney(i.quantity * i.price)}</td>
                <td class="num">${actions}</td>
            </tr>`;
        }).join('');

        bindItemActions();
        renderPagination('#items-pagination', data.total, data.page, Math.ceil(data.total / data.perPage), (p) => {
            state.itemsPage = p;
            loadItems();
        });
    } catch (e) {
        showToast(e.message, 'error');
    }
}

function bindItemActions() {
    $$('#items-table tbody [data-action]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const { action, id } = btn.dataset;
            if (action === 'edit') openItemModal(id);
            if (action === 'delete') {
                if (!confirm('Excluir este item? Esta ação remove o item do cadastro.')) return;
                try {
                    await apiFetch(`${API.items}/${id}`, { method: 'DELETE' });
                    showToast('Item excluído.', 'success');
                    loadItems();
                } catch (e) { showToast(e.message, 'error'); }
            }
            if (action === 'label') addLabelById(id);
        });
    });
}

function renderPagination(sel, total, page, pages, cb) {
    const el = $(sel);
    if (pages <= 1) { el.innerHTML = ''; return; }
    let html = '';
    const showPages = [];
    for (let p = 1; p <= pages; p++) {
        if (p === 1 || p === pages || Math.abs(p - page) <= 2) showPages.push(p);
    }
    let prev = 0;
    showPages.forEach(p => {
        if (p - prev > 1) html += '<button disabled>…</button>';
        html += `<button class="${p === page ? 'active' : ''}" data-page="${p}">${p}</button>`;
        prev = p;
    });
    el.innerHTML = html;
    el.querySelectorAll('button[data-page]').forEach(b => {
        b.addEventListener('click', () => cb(parseInt(b.dataset.page)));
    });
}

async function loadCategories() {
    try {
        const data = await apiFetch(API.items + '?per_page=1');
        const cats = data.categories || [];
        $('#item-category-filter').innerHTML = '<option value="">Todas as categorias</option>' +
            cats.map(c => `<option>${escapeHtml(c)}</option>`).join('');
        $('#category-list').innerHTML = cats.map(c => `<option value="${escapeHtml(c)}">`).join('');
    } catch { /* noop */ }
}

/* ================== Modal de item ================== */

function openItemModal(id = null) {
    if (!userCan('items.create') && !userCan('items.edit')) {
        showToast('Acesso negado.', 'error');
        return;
    }
    $('#item-modal-title').textContent = id ? 'Editar item' : 'Novo item';
    $('#item-form').reset();

    if (id) {
        apiFetch(`${API.items}/${id}`).then(({ item }) => {
            $('#item-id').value = item.id;
            $('#item-code').value = item.code;
            $('#item-name').value = item.name;
            $('#item-category').value = item.category;
            $('#item-unit').value = item.unit;
            $('#item-quantity').value = item.quantity;
            $('#item-min-stock').value = item.min_stock;
            $('#item-price').value = item.price;
            $('#item-location').value = item.location;
            $('#item-quantity').parentElement.style.display = 'none';
        }).catch(e => showToast(e.message, 'error'));
    } else {
        $('#item-quantity').parentElement.style.display = '';
        setTimeout(() => $('#item-code').focus(), 50);
    }
    $('#item-modal').classList.remove('hidden');
}

function closeItemModal() {
    $('#item-modal').classList.add('hidden');
}

/* ================== Entrada ================== */

function setupEntryScan() {
    setupScanner('#entry-scan', async (code) => {
        try {
            const data = await apiFetch(`${API.items}?search=${encodeURIComponent(code)}&per_page=5`);
            const item = data.items.find(i => i.code === code.replace(/\s/g, ''));

            if (!item) {
                setFeedback($('#entry-feedback'),
                    `Código <strong>${escapeHtml(code)}</strong> não cadastrado. <button class="btn btn-primary btn-sm" id="btn-register-from-scan" data-code="${escapeHtml(code)}">Cadastrar agora</button>`,
                    'warn');
                $('#btn-register-from-scan')?.addEventListener('click', () => {
                    $('#item-code').value = code;
                    openItemModal();
                });
                return;
            }

            addToCart('entry', item);
            setFeedback($('#entry-feedback'), `✓ <strong>${escapeHtml(item.name)}</strong> adicionado à entrada.`, 'ok');
            renderCart('entry');
        } catch (e) {
            setFeedback($('#entry-feedback'), e.message, 'err');
        }
    });
}

function addToCart(type, item, qty = 1) {
    const cart = state[type === 'entry' ? 'entryCart' : 'exitCart'];
    const existing = cart.get(String(item.id));
    if (existing) {
        existing.qty += qty;
    } else {
        cart.set(String(item.id), {
            id: item.id, code: item.code, name: item.name, qty,
        });
    }
}

function removeFromCart(type, id) {
    const cart = state[type === 'entry' ? 'entryCart' : 'exitCart'];
    cart.delete(String(id));
    renderCart(type);
}

function renderCart(type) {
    const cart = state[type === 'entry' ? 'entryCart' : 'exitCart'];
    const tbody = $(`#${type}-table tbody`);
    const emptyEl = $(`#${type}-list-empty`);

    if (!cart.size) {
        tbody.innerHTML = '';
        emptyEl.style.display = 'block';
        return;
    }
    emptyEl.style.display = 'none';

    tbody.innerHTML = Array.from(cart.values()).map(item => `
        <tr>
            <td><span class="badge ok">${escapeHtml(item.code)}</span></td>
            <td>${escapeHtml(item.name)}</td>
            <td class="num">
                <input type="number" class="qty-input cart-qty" data-id="${item.id}" data-type="${type}"
                       value="${item.qty}" min="1" step="any" style="text-align:right">
            </td>
            <td class="num"><button class="btn-icon" data-action="remove" data-id="${item.id}" data-type="${type}">✕</button></td>
        </tr>`).join('');

    $$('#entry-table tbody .cart-qty').forEach(inp => {
        inp.addEventListener('change', () => {
            const v = parseFloat(inp.value) || 1;
            state.entryCart.get(inp.dataset.id).qty = Math.max(0.001, v);
        });
    });
    $$('#exit-table tbody .cart-qty').forEach(inp => {
        inp.addEventListener('change', () => {
            const v = parseFloat(inp.value) || 1;
            state.exitCart.get(inp.dataset.id).qty = Math.max(0.001, v);
        });
    });
    $$('.btn-icon[data-action="remove"]').forEach(btn => {
        btn.addEventListener('click', () => removeFromCart(btn.dataset.type, btn.dataset.id));
    });
}

async function confirmMovement(type) {
    const viewId = type === 'in' ? 'entry' : 'exit';
    const cart = state[viewId === 'entry' ? 'entryCart' : 'exitCart'];
    if (!cart.size) {
        showToast('Adicione pelo menos um item.', 'error');
        return;
    }

    const items = Array.from(cart.values()).map(i => ({ code: i.code, quantity: i.qty }));
    const note = $(`#${viewId}-note`).value.trim();

    try {
        const data = await apiFetch(API.movements, {
            method: 'POST',
            body: { type, items, note },
        });

        state[viewId === 'entry' ? 'entryCart' : 'exitCart'].clear();
        renderCart(viewId);
        setFeedback($(`#${viewId}-feedback`), '', '');

        showResultModal(
            type === 'in' ? 'Entrada registrada!' : 'Saída registrada!',
            data.message,
            data.ref_code,
            type === 'out' ? 'Código de barras do documento de saída' : 'Código de barras do documento de entrada'
        );

        $(`#${viewId}-scan`).focus();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

/* ================== Modal de resultado ================== */

function showResultModal(title, message, refCode, subtitle) {
    $('#result-title').textContent = title;
    $('#result-message').textContent = message;
    $('#result-ref').textContent = subtitle + ' · ' + refCode;
    const box = $('#result-barcode');
    box.innerHTML = '';
    try {
        JsBarcode(box, refCode, {
            format: 'CODE128',
            width: 2, height: 60, displayValue: true, fontSize: 14,
        });
    } catch { /* noop */ }
    $('#result-modal').classList.remove('hidden');
}

function closeResultModal() {
    $('#result-modal').classList.add('hidden');
}

/* ================== Saída ================== */

function setupExitScan() {
    setupScanner('#exit-scan', async (code) => {
        try {
            const data = await apiFetch(`${API.items}?search=${encodeURIComponent(code)}&per_page=5`);
            const item = data.items.find(i => i.code === code.replace(/\s/g, ''));

            if (!item) {
                setFeedback($('#exit-feedback'), `Código <strong>${escapeHtml(code)}</strong> não encontrado no estoque.`, 'err');
                return;
            }

            if (parseFloat(item.quantity) <= 0) {
                setFeedback($('#exit-feedback'), `⚠ <strong>${escapeHtml(item.name)}</strong> está sem estoque.`, 'err');
                return;
            }

            addToCart('exit', item);
            setFeedback($('#exit-feedback'), `✓ <strong>${escapeHtml(item.name)}</strong> adicionado à saída.`, 'ok');
            renderCart('exit');
        } catch (e) {
            setFeedback($('#exit-feedback'), e.message, 'err');
        }
    });
}

/* ================== Histórico ================== */

async function loadHistory() {
    const type = $('#history-type').value;
    const search = $('#history-search').value.trim();

    const params = new URLSearchParams({
        limit: state.historyLimit,
        offset: state.historyOffset,
        type, search,
    });

    try {
        const data = await apiFetch(API.movements + '?' + params.toString());
        const tbody = $('#history-table tbody');

        if (!data.movements.length) {
            tbody.innerHTML = `<tr><td colspan="8"><div class="empty">Sem movimentações.</div></td></tr>`;
            $('#history-pagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = data.movements.map(m => `
            <tr>
                <td><span class="badge ok">${escapeHtml(m.ref_code)}</span></td>
                <td>${fmtDateTime(m.created_at)}</td>
                <td><span class="badge ${m.type}">${m.type === 'in' ? 'ENTRADA' : m.type === 'out' ? 'SAÍDA' : 'AJUSTE'}</span></td>
                <td><span class="badge ok">${escapeHtml(m.item_code)}</span></td>
                <td>${escapeHtml(m.item_name)}</td>
                <td class="num ${m.type === 'out' ? 'text-danger' : ''}">${m.type === 'out' ? '-' : '+'}${fmtNum(m.quantity)}</td>
                <td>${escapeHtml(m.user_name || '—')}</td>
                <td>${escapeHtml(m.note || '')}</td>
            </tr>`).join('');

        const pages = Math.ceil(data.total / state.historyLimit);
        const cur = Math.floor(state.historyOffset / state.historyLimit) + 1;
        renderPagination('#history-pagination', data.total, cur, pages, (p) => {
            state.historyOffset = (p - 1) * state.historyLimit;
            loadHistory();
        });
    } catch (e) {
        showToast(e.message, 'error');
    }
}

/* ================== Relatórios ================== */

function reportFilters() {
    return new URLSearchParams({
        period_start: $('#report-from').value,
        period_end: $('#report-to').value,
        type: $('#report-type').value,
        user_id: $('#report-user').value,
        search: $('#report-search').value.trim(),
    });
}

async function loadReports(mode = state.reportMode) {
    state.reportMode = mode;
    const params = reportFilters();

    try {
        const data = await apiFetch(API.reports + '?' + params.toString() +
            (mode === 'by_item' ? '&action=by_item' : mode === 'low_stock' ? '&action=low_stock' : ''));

        // Preenche filtro de usuários (uma vez)
        if (data.users) {
            const cur = $('#report-user').value;
            $('#report-user').innerHTML = '<option value="">Todos os usuários</option>' +
                data.users.map(u => `<option value="${u.id}" ${String(u.id) === cur ? 'selected' : ''}>${escapeHtml(u.name || u.email)}</option>`).join('');
        }

        renderReport(mode, data);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

function renderReport(mode, data) {
    $('#report-title').textContent = data.title || 'Relatório';

    if (mode === 'by_item') {
        $('#report-summary').innerHTML = `
            <div class="card"><div class="label">Produtos com movimentação</div><div class="value">${fmtNum(data.items.length)}</div></div>`;

        const tbody = $('#report-table tbody');
        if (!data.items.length) {
            tbody.innerHTML = `<tr><td colspan="8"><div class="empty">Sem dados no período.</div></td></tr>`;
            return;
        }
        tbody.innerHTML = data.items.map(i => `
            <tr>
                <td><span class="badge ok">${escapeHtml(i.item_code)}</span></td>
                <td colspan="1">—</td>
                <td>—</td>
                <td>—</td>
                <td><strong>${escapeHtml(i.item_name)}</strong></td>
                <td class="num">+${fmtNum(i.total_in)} / -${fmtNum(i.total_out)}</td>
                <td class="num">${fmtNum(i.balance)}</td>
                <td>${fmtNum(i.movements_count)} mov.</td>
            </tr>`).join('');
        return;
    }

    if (mode === 'low_stock') {
        $('#report-summary').innerHTML = `
            <div class="card danger"><div class="label">Produtos com estoque baixo</div><div class="value">${fmtNum(data.total_low)}</div></div>`;

        const tbody = $('#report-table tbody');
        if (!data.items.length) {
            tbody.innerHTML = `<tr><td colspan="8"><div class="empty">Nenhum produto com estoque baixo.</div></td></tr>`;
            return;
        }
        tbody.innerHTML = data.items.map(i => `
            <tr>
                <td><span class="badge ok">${escapeHtml(i.code)}</span></td>
                <td>—</td>
                <td>—</td>
                <td>—</td>
                <td><strong>${escapeHtml(i.name)}</strong></td>
                <td class="num">${fmtNum(i.quantity)} ${escapeHtml(i.unit)}</td>
                <td class="num">mín. ${fmtNum(i.min_stock)}</td>
                <td>${escapeHtml(i.location || '')}</td>
            </tr>`).join('');
        return;
    }

    // mode === 'movements'
    const s = data.summary;
    $('#report-summary').innerHTML = `
        <div class="card"><div class="label">Movimentações</div><div class="value">${fmtNum(s.total_movements)}</div></div>
        <div class="card success"><div class="label">Total entradas</div><div class="value">+${fmtNum(s.total_in)}</div></div>
        <div class="card warning"><div class="label">Total saídas</div><div class="value">-${fmtNum(s.total_out)}</div></div>
        <div class="card"><div class="label">Usuários com movimentação</div><div class="value">${fmtNum(s.total_users)}</div></div>`;

    const tbody = $('#report-table tbody');
    if (!data.movements.length) {
        tbody.innerHTML = `<tr><td colspan="8"><div class="empty">Sem movimentações no período.</div></td></tr>`;
        return;
    }
    tbody.innerHTML = data.movements.map(m => `
        <tr>
            <td><span class="badge ok">${escapeHtml(m.ref_code)}</span></td>
            <td>${fmtDateTime(m.created_at)}</td>
            <td><span class="badge ${m.type}">${m.type === 'in' ? 'ENTRADA' : m.type === 'out' ? 'SAÍDA' : 'AJUSTE'}</span></td>
            <td><span class="badge ok">${escapeHtml(m.item_code)}</span></td>
            <td>${escapeHtml(m.item_name)}</td>
            <td class="num ${m.type === 'out' ? 'text-danger' : ''}">${m.type === 'out' ? '-' : '+'}${fmtNum(m.quantity)}</td>
            <td>${escapeHtml(m.user_name || '—')}</td>
            <td>${escapeHtml(m.note || '')}</td>
        </tr>`).join('');
}

function exportReportCSV() {
    const mode = state.reportMode;
    const rows = [];
    if (mode === 'movements') {
        rows.push(['Doc.', 'Data/Hora', 'Tipo', 'Código', 'Produto', 'Quantidade', 'Usuário', 'Obs.']);
        $$('#report-table tbody tr').forEach(tr => {
            const cells = $$('td', tr).map(td => td.textContent.replace(/\s+/g, ' ').trim());
            rows.push(cells);
        });
    } else {
        rows.push(['Código', 'Produto', 'Entradas', 'Saídas', 'Saldo']);
        $$('#report-table tbody tr').forEach(tr => {
            const cells = $$('td', tr).map(td => td.textContent.replace(/\s+/g, ' ').trim());
            rows.push([cells[0], cells[4], cells[5], cells[6], cells[7]]);
        });
    }

    const csv = rows.map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(';')).join('\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    const url = URL.createObjectURL(blob);
    a.href = url;
    a.download = 'relatorio_estoque.csv';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 100);
}

function printReport() {
    const btn = $('#btn-report-print');
    const wrap = $('#report-wrap');
    if (!wrap) return;
    window.print();
}

/* ================== Usuários (admin) ================== */

async function loadUsers() {
    try {
        const data = await apiFetch(API.users);
        const tbody = $('#users-table tbody');
        const me = state.user.id;

        tbody.innerHTML = data.users.map(u => `
            <tr class="${u.active == 0 ? 'row-inactive' : ''}">
                <td><strong>${escapeHtml(u.name)}</strong>${u.id == me ? ' <span class="badge ok">você</span>' : ''}</td>
                <td>${escapeHtml(u.email)}</td>
                <td><span class="badge ${u.role === 'admin' ? 'admin' : ''}">${u.role === 'admin' ? 'Administrador' : 'Operador'}</span></td>
                <td><span class="badge ${u.active == 1 ? 'ok' : 'empty'}">${u.active == 1 ? 'Ativo' : 'Desativado'}</span></td>
                <td>${u.last_login_at ? fmtDateTime(u.last_login_at) : 'Nunca acessou'}</td>
                <td class="num">${fmtNum(u.movements_count)}</td>
                <td class="num">
                    <button class="btn-icon edit" data-action="edit" data-id="${u.id}" title="Editar / ver movimentações">✏️</button>
                    <button class="btn-icon" data-action="toggle" data-id="${u.id}" title="${u.active == 1 ? 'Desativar' : 'Reativar'}">${u.active == 1 ? '⏸️' : '▶️'}</button>
                </td>
            </tr>`).join('');

        $$('#users-table tbody [data-action]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const { action, id } = btn.dataset;
                if (action === 'edit') openUserModal(parseInt(id));
                if (action === 'toggle') {
                    const user = data.users.find(x => String(x.id) === id);
                    const disable = user.active == 1;
                    const msg = disable
                        ? `Desativar ${user.name}? Ele não poderá mais acessar o sistema.`
                        : `Reativar ${user.name}?`;
                    if (!confirm(msg)) return;
                    try {
                        await apiFetch(`${API.users}/${id}`, { method: 'PUT', body: { active: disable ? 0 : 1 } });
                        showToast(disable ? 'Usuário desativado.' : 'Usuário reativado.', 'success');
                        loadUsers();
                    } catch (e) { showToast(e.message, 'error'); }
                }
            });
        });
    } catch (e) {
        showToast(e.message, 'error');
    }
}

function openUserModal(id = null) {
    $('#user-modal-title').textContent = id ? 'Editar usuário' : 'Novo usuário';
    $('#user-form').reset();
    $('#u-id').value = '';
    $('#u-active').value = '1';
    $('#u-role').value = 'operator';
    $('#u-password-field').style.display = '';
    $('#u-reset-field').classList.add('hidden');

    if (id) {
        apiFetch(`${API.users}/${id}`).then(({ user, recent_movements }) => {
            $('#u-id').value = user.id;
            $('#u-name').value = user.name;
            $('#u-email').value = user.email;
            $('#u-username').value = user.username || '';
            $('#u-role').value = user.role;
            $('#u-active').value = String(user.active);
            $('#u-password-field').style.display = 'none';
            $('#u-reset-field').classList.remove('hidden');

            // Mostra movimentações recentes do usuário
            let html = '<h4 style="margin:12px 0 6px;font-size:13px">Últimas movimentações</h4>';
            if (recent_movements && recent_movements.length) {
                html += recent_movements.map(m => `
                    <div class="list-row">
                        <span class="badge ${m.type}">${m.type === 'in' ? 'ENTRADA' : 'SAÍDA'}</span>
                        <strong>${escapeHtml(m.item_name)}</strong>
                        <small>${m.type === 'in' ? '+' : '-'}${fmtNum(m.quantity)} · ${escapeHtml(m.ref_code)} · ${fmtDateTime(m.created_at)}</small>
                    </div>`).join('');
            } else {
                html += '<div class="empty">Sem movimentações.</div>';
            }
            $('#u-reset-field').insertAdjacentHTML('afterend', `<div id="user-movements">${html}</div>`);
        }).catch(e => showToast(e.message, 'error'));
    } else {
        $('#user-movements')?.remove();
    }

    $('#user-modal').classList.remove('hidden');
}

function closeUserModal() {
    $('#user-modal').classList.add('hidden');
    $('#user-movements')?.remove();
}

/* ================== Minha conta ================== */

async function loadAccount() {
    if (!state.user) return;
    $('#account-info').innerHTML = `
        <div class="list-row"><span class="badge ok">${escapeHtml(state.user.name || '')}</span></div>
        <div class="list-row"><strong>E-mail</strong><small>${escapeHtml(state.user.email)}</small></div>
        <div class="list-row"><strong>Usuário</strong><small>${escapeHtml(state.user.username || '—')}</small></div>
        <div class="list-row"><strong>Perfil</strong><small>${state.user.role === 'admin' ? 'Administrador' : 'Operador'}</small></div>
        <div class="list-row"><strong>Último acesso</strong><small>${state.user.last_login_at ? fmtDateTime(state.user.last_login_at) : '—'}</small></div>
    `;
}

/* ================== Etiquetas ================== */

async function addLabelById(id) {
    try {
        const { item } = await apiFetch(`${API.items}/${id}`);
        addLabelToSheet(item);
    } catch (e) { showToast(e.message, 'error'); }
}

function addLabelToSheet(item, qty = 1) {
    for (let i = 0; i < qty; i++) {
        state.labels.push(item);
    }
    renderLabels();
}

function renderLabels() {
    const sheet = $('#label-sheet');
    if (!state.labels.length) {
        sheet.innerHTML = '<div class="empty">Nenhuma etiqueta gerada. Escaneie ou informe um código acima.</div>';
        return;
    }
    sheet.innerHTML = state.labels.map((it, idx) => `
        <div class="label-item">
            <button class="l-remove" data-idx="${idx}">✕</button>
            <div class="l-name">${escapeHtml(it.name)}</div>
            <div class="l-code">${escapeHtml(it.code)}</div>
            <div class="l-barcode"><svg id="lb-${idx}"></svg></div>
            <div class="l-price">${fmtMoney(it.price)}</div>
        </div>`).join('');

    state.labels.forEach((it, idx) => {
        try {
            JsBarcode(`#lb-${idx}`, String(it.code), {
                format: 'CODE128', width: 2, height: 50,
                displayValue: true, fontSize: 12, margin: 2,
            });
        } catch { /* noop */ }
    });

    $$('#label-sheet .l-remove').forEach(btn => {
        btn.addEventListener('click', () => {
            state.labels.splice(parseInt(btn.dataset.idx), 1);
            renderLabels();
        });
    });
}

function setupLabelScan() {
    $('#label-code').addEventListener('keydown', async (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const code = $('#label-code').value.trim();
            const qty = Math.max(1, parseInt($('#label-qty').value) || 1);
            if (!code) return;
            try {
                const data = await apiFetch(`${API.items}?search=${encodeURIComponent(code)}&per_page=5`);
                const item = data.items.find(i => i.code === code.replace(/\s/g, ''));
                if (!item) {
                    setFeedback($('#label-feedback'), `Código ${escapeHtml(code)} não encontrado.`, 'err');
                    return;
                }
                addLabelToSheet(item, qty);
                setFeedback($('#label-feedback'), `✓ ${qty} etiqueta(s) gerada(s).`, 'ok');
                $('#label-code').value = '';
                $('#label-code').focus();
            } catch (err) {
                setFeedback($('#label-feedback'), err.message, 'err');
            }
        }
    });
}

function printLabels() {
    if (!state.labels.length) {
        showToast('Nenhuma etiqueta para imprimir.', 'error');
        return;
    }
    window.print();
}

function printHistory() {
    const wrap = $('#history-wrap');
    if (!wrap) return;
    window.print();
}

/* ================== Init ================== */

function initEvents() {
    // Login
    $('#login-form').addEventListener('submit', (e) => {
        e.preventDefault();
        doLogin($('#login-identifier').value.trim(), $('#login-password').value);
    });
    $('#btn-logout').addEventListener('click', doLogout);

    // Navegação
    $$('#main-tabs .tab').forEach(t => t.addEventListener('click', () => switchView(t.dataset.view)));

    // Itens
    $('#item-search').addEventListener('input', () => {
        clearTimeout(state.searchTimer);
        state.itemsPage = 1;
        state.searchTimer = setTimeout(loadItems, 300);
    });
    $('#item-category-filter').addEventListener('change', () => { state.itemsPage = 1; loadItems(); });
    $('#item-low-filter').addEventListener('change', () => { state.itemsPage = 1; loadItems(); });

    $('#btn-new-item').addEventListener('click', () => openItemModal());
    $('#btn-close-modal').addEventListener('click', closeItemModal);
    $('#btn-modal-cancel').addEventListener('click', closeItemModal);
    $('#item-modal').addEventListener('click', (e) => {
        if (e.target === $('#item-modal')) closeItemModal();
    });

    $('#item-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = $('#item-id').value;
        const payload = {
            code: $('#item-code').value.trim(),
            name: $('#item-name').value.trim(),
            category: $('#item-category').value.trim(),
            unit: $('#item-unit').value.trim(),
            min_stock: parseFloat($('#item-min-stock').value) || 0,
            price: parseFloat($('#item-price').value) || 0,
            location: $('#item-location').value.trim(),
        };

        try {
            if (id) {
                await apiFetch(`${API.items}/${id}`, { method: 'PUT', body: payload });
                showToast('Item atualizado!', 'success');
            } else {
                payload.quantity = parseFloat($('#item-quantity').value) || 0;
                await apiFetch(API.items, { method: 'POST', body: payload });
                showToast('Item cadastrado!', 'success');
                $('#entry-feedback').innerHTML = '';
            }
            closeItemModal();
            loadItems();
        } catch (err) {
            showToast(err.message, 'error');
        }
    });

    // Entrada
    setupEntryScan();
    $('#btn-entry-add').addEventListener('click', async () => {
        const code = $('#entry-scan').value.trim();
        if (!code) return;
        try {
            const data = await apiFetch(`${API.items}?search=${encodeURIComponent(code)}&per_page=5`);
            const item = data.items.find(i => i.code === code.replace(/\s/g, ''));
            if (!item) throw new Error('Código não cadastrado.');
            addToCart('entry', item);
            renderCart('entry');
            setFeedback($('#entry-feedback'), `✓ <strong>${escapeHtml(item.name)}</strong> adicionado à entrada.`, 'ok');
            $('#entry-scan').value = '';
            $('#entry-scan').focus();
        } catch (e) {
            setFeedback($('#entry-feedback'), e.message, 'err');
        }
    });
    $('#btn-entry-confirm').addEventListener('click', () => confirmMovement('in'));

    // Saída
    setupExitScan();
    $('#btn-exit-add').addEventListener('click', async () => {
        const code = $('#exit-scan').value.trim();
        if (!code) return;
        try {
            const data = await apiFetch(`${API.items}?search=${encodeURIComponent(code)}&per_page=5`);
            const item = data.items.find(i => i.code === code.replace(/\s/g, ''));
            if (!item) throw new Error('Código não encontrado.');
            if (parseFloat(item.quantity) <= 0) throw new Error(`${item.name} está sem estoque.`);
            addToCart('exit', item);
            renderCart('exit');
            setFeedback($('#exit-feedback'), `✓ <strong>${escapeHtml(item.name)}</strong> adicionado à saída.`, 'ok');
            $('#exit-scan').value = '';
            $('#exit-scan').focus();
        } catch (e) {
            setFeedback($('#exit-feedback'), e.message, 'err');
        }
    });
    $('#btn-exit-confirm').addEventListener('click', () => confirmMovement('out'));

    // Histórico
    $('#history-type').addEventListener('change', () => { state.historyOffset = 0; loadHistory(); });
    $('#history-search').addEventListener('input', () => {
        clearTimeout(state.searchTimer);
        state.historyOffset = 0;
        state.searchTimer = setTimeout(loadHistory, 300);
    });
    $('#btn-print-history').addEventListener('click', printHistory);

    // Relatórios
    $('#btn-report-run').addEventListener('click', () => loadReports('movements'));
    $('#btn-report-by-item').addEventListener('click', () => loadReports('by_item'));
    $('#btn-report-low').addEventListener('click', () => loadReports('low_stock'));
    $('#btn-report-export').addEventListener('click', exportReportCSV);
    $('#btn-report-print').addEventListener('click', printReport);

    // Usuários
    $('#btn-new-user').addEventListener('click', () => openUserModal());
    $('#btn-close-user-modal').addEventListener('click', closeUserModal);
    $('#btn-user-cancel').addEventListener('click', closeUserModal);
    $('#user-modal').addEventListener('click', (e) => {
        if (e.target === $('#user-modal')) closeUserModal();
    });

    $('#user-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = $('#u-id').value;
        const payload = {
            name: $('#u-name').value.trim(),
            email: $('#u-email').value.trim(),
            username: $('#u-username').value.trim(),
            role: $('#u-role').value,
            active: parseInt($('#u-active').value) || 1,
        };
        try {
            if (id) {
                await apiFetch(`${API.users}/${id}`, { method: 'PUT', body: payload });
                showToast('Usuário atualizado!', 'success');
            } else {
                payload.password = $('#u-password').value;
                await apiFetch(API.users, { method: 'POST', body: payload });
                showToast('Usuário criado!', 'success');
            }
            closeUserModal();
            loadUsers();
        } catch (err) {
            showToast(err.message, 'error');
        }
    });

    $('#btn-user-reset').addEventListener('click', async () => {
        const id = $('#u-id').value;
        if (!id || !confirm('Gerar uma nova senha temporária para este usuário?')) return;
        try {
            const newPass = Math.random().toString(36).slice(2, 8) + 'A1';
            const data = await apiFetch(`${API.users}/${id}/password`, {
                method: 'POST', body: { new_password: newPass },
            });
            alert(data.message);
            showToast('Senha redefinida!', 'success');
        } catch (err) {
            showToast(err.message, 'error');
        }
    });

    // Minha conta
    $('#change-password-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const data = await apiFetch(API.account, {
                method: 'PUT',
                body: {
                    current_password: $('#cp-current').value,
                    new_password: $('#cp-new').value,
                    confirm_password: $('#cp-confirm').value,
                },
            });
            showToast(data.message, 'success');
            $('#change-password-form').reset();
        } catch (err) {
            showToast(err.message, 'error');
        }
    });

    // Etiquetas
    setupLabelScan();
    $('#btn-label-add').addEventListener('click', async () => {
        const code = $('#label-code').value.trim();
        const qty = Math.max(1, parseInt($('#label-qty').value) || 1);
        if (!code) return;
        try {
            const data = await apiFetch(`${API.items}?search=${encodeURIComponent(code)}&per_page=5`);
            const item = data.items.find(i => i.code === code.replace(/\s/g, ''));
            if (!item) throw new Error('Código não encontrado.');
            addLabelToSheet(item, qty);
            setFeedback($('#label-feedback'), `✓ ${qty} etiqueta(s) gerada(s).`, 'ok');
            $('#label-code').value = '';
            $('#label-code').focus();
        } catch (e) {
            setFeedback($('#label-feedback'), e.message, 'err');
        }
    });
    $('#btn-label-clear').addEventListener('click', () => {
        state.labels = [];
        renderLabels();
    });
    $('#btn-label-print').addEventListener('click', printLabels);

    // Resultado
    $('#btn-close-result').addEventListener('click', closeResultModal);
    $('#btn-close-result-2').addEventListener('click', closeResultModal);
    $('#btn-result-print').addEventListener('click', () => {
        const box = $('#result-barcode');
        if (box.innerHTML) window.print();
    });
    $('#result-modal').addEventListener('click', (e) => {
        if (e.target === $('#result-modal')) closeResultModal();
    });

    // Atalhos de teclado
    document.addEventListener('keydown', (e) => {
        if (!state.user) return;
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (e.key === 'F2') { e.preventDefault(); switchView('entry'); }
        if (e.key === 'F3') { e.preventDefault(); switchView('exit'); }
        if (e.key === 'F4') { e.preventDefault(); switchView('items'); }
        if (e.key === 'F5') { e.preventDefault(); switchView('history'); }
        if (e.key === 'F6') { e.preventDefault(); switchView('reports'); }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initEvents();
    checkSession();
    renderCart('entry');
    renderCart('exit');
    renderLabels();
});
