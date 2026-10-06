(() => {
    'use strict';

    const cfg = window.APP_CONFIG || {};
    const state = {
        user: null,
        csrf: null,
        categories: [],
        items: [],
        googleInitialized: false,
        loginFallbackTimer: null,
    };

    const $ = (selector) => document.querySelector(selector);
    const $$ = (selector) => [...document.querySelectorAll(selector)];

    const kindNames = {
        asset: 'Eiendeler',
        liability: 'Gjeld',
        income: 'Inntekter',
        expense: 'Utgifter',
    };

    const money = new Intl.NumberFormat('nb-NO', {
        style: 'currency',
        currency: 'NOK',
        maximumFractionDigits: 0,
    });

    const fullMoney = new Intl.NumberFormat('nb-NO', {
        style: 'currency',
        currency: 'NOK',
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });

    function localDate(date = new Date()) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function currentMonth() {
        return localDate().slice(0, 7);
    }

    function firstOfMonth() {
        return currentMonth() + '-01';
    }

    function toast(message, type = 'ok') {
        const node = $('#toast');
        node.textContent = message;
        node.classList.toggle('error', type === 'error');
        node.classList.add('show');
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => node.classList.remove('show'), 3200);
    }

    async function api(action, options = {}) {
        const method = options.method || 'GET';
        const url = new URL(cfg.apiUrl, window.location.href);
        url.searchParams.set('action', action);
        Object.entries(options.query || {}).forEach(([key, value]) => url.searchParams.set(key, value));

        const headers = { ...(options.headers || {}) };
        if (method !== 'GET' && state.csrf) {
            headers['X-CSRF-Token'] = state.csrf;
        }
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers,
            body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
        });

        let data = {};
        try {
            data = await response.json();
        } catch (_) {
            data = { error: 'Serveren ga et ugyldig svar.' };
        }

        if (!response.ok) {
            const err = new Error(data.error || 'Noe gikk galt.');
            err.status = response.status;
            throw err;
        }
        return data;
    }

    async function init() {
        $('#snapshot-date').value = firstOfMonth();
        $('#flow-date').value = localDate();
        $('#flow-month').value = currentMonth();

        wireEvents();

        try {
            const me = await api('me');
            cfg.googleClientId = me.googleClientId || cfg.googleClientId;
            if (me.authenticated) {
                state.user = me.user;
                state.csrf = me.csrf;
                await showApp();
            } else {
                showLogin();
            }
        } catch (error) {
            showLogin();
            toast(error.message, 'error');
        }
    }

    function showLogin() {
        $('#app-view').hidden = true;
        $('#auth-loading').hidden = true;
        $('#login-view').hidden = false;

        const fallback = $('#login-fallback');
        const status = $('#auto-login-status');
        fallback.hidden = true;
        status.hidden = false;

        if (!cfg.googleClientId) {
            status.hidden = true;
            $('#login-config-warning').hidden = false;
            fallback.hidden = true;
            return;
        }

        let attempts = 0;
        const render = () => {
            attempts += 1;
            if (!window.google?.accounts?.id) {
                if (attempts < 80) {
                    setTimeout(render, 100);
                } else {
                    status.hidden = true;
                    fallback.hidden = false;
                    toast('Google-innlogging kunne ikke lastes. Prøv å laste siden på nytt.', 'error');
                }
                return;
            }

            if (!state.googleInitialized) {
                google.accounts.id.initialize({
                    client_id: cfg.googleClientId,
                    callback: handleGoogleCredential,
                    auto_select: true,
                    button_auto_select: true,
                    use_fedcm_for_button: true,
                    cancel_on_tap_outside: true,
                    context: 'signin',
                    itp_support: true,
                });
                state.googleInitialized = true;
            }

            google.accounts.id.renderButton($('#google-signin'), {
                type: 'standard',
                theme: 'filled_black',
                size: 'large',
                shape: 'rectangular',
                text: 'continue_with',
                logo_alignment: 'left',
                locale: 'nb',
                width: 340,
            });

            google.accounts.id.prompt();

            clearTimeout(state.loginFallbackTimer);
            state.loginFallbackTimer = setTimeout(() => {
                if (state.user) return;
                status.hidden = true;
                fallback.hidden = false;
            }, 1200);
        };

        render();
    }

    async function handleGoogleCredential(response) {
        clearTimeout(state.loginFallbackTimer);
        try {
            const result = await api('googleLogin', {
                method: 'POST',
                body: { credential: response.credential },
            });
            state.user = result.user;
            state.csrf = result.csrf;
            await showApp();
            toast('Innlogging fullført.');
        } catch (error) {
            toast(error.message, 'error');
        }
    }
    window.handleGoogleCredential = handleGoogleCredential;

    async function showApp() {
        clearTimeout(state.loginFallbackTimer);
        $('#auth-loading').hidden = true;
        $('#login-view').hidden = true;
        $('#app-view').hidden = false;

        $('#user-name').textContent = state.user.name || state.user.email;
        $('#user-email').textContent = state.user.email || '';
        if (state.user.picture) {
            $('#user-avatar').src = state.user.picture;
            $('#user-avatar').hidden = false;
        }

        await loadReferenceData();
        await Promise.all([loadSummary(), loadSnapshot(), loadFlows()]);
    }

    function wireEvents() {
        $$('.tab').forEach((button) => button.addEventListener('click', () => switchView(button.dataset.view)));
        $$('.jump-log').forEach((button) => button.addEventListener('click', () => switchView('log')));

        $('#logout-btn').addEventListener('click', logout);
        $('#refresh-summary').addEventListener('click', loadSummary);
        $('#snapshot-date').addEventListener('change', loadSnapshot);
        $('#flow-month').addEventListener('change', loadFlows);
        $('#flow-kind').addEventListener('change', populateFlowItems);
        $('#item-kind').addEventListener('change', populateItemCategories);
        $('#category-form').addEventListener('submit', createCategory);
        $('#item-form').addEventListener('submit', createItem);
        $('#flow-form').addEventListener('submit', createFlow);
        $('#save-snapshot-draft').addEventListener('click', () => saveSnapshot(false));
        $('#save-snapshot-complete').addEventListener('click', () => saveSnapshot(true));
    }

    function switchView(view) {
        $$('.tab').forEach((tab) => tab.classList.toggle('active', tab.dataset.view === view));
        $$('.view').forEach((panel) => panel.classList.toggle('active', panel.id === `view-${view}`));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function logout() {
        try {
            if (window.google?.accounts?.id) {
                google.accounts.id.disableAutoSelect();
            }
            await api('logout', { method: 'POST', body: {} });
            state.user = null;
            state.csrf = null;
            window.location.reload();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function loadReferenceData() {
        try {
            const [categories, items] = await Promise.all([
                api('categories'),
                api('items'),
            ]);
            state.categories = categories.categories;
            state.items = items.items;
            populateItemCategories();
            populateFlowItems();
            renderStructure();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    function populateItemCategories() {
        const kind = $('#item-kind').value;
        const select = $('#item-category');
        const categories = state.categories.filter((category) => category.kind === kind);
        select.innerHTML = categories.map((category) =>
            `<option value="${category.id}">${escapeHtml(category.name)}</option>`
        ).join('');
        select.disabled = categories.length === 0;
    }

    function populateFlowItems() {
        const kind = $('#flow-kind').value;
        const select = $('#flow-item');
        const items = state.items.filter((item) => item.kind === kind);
        select.innerHTML = items.map((item) =>
            `<option value="${item.id}">${escapeHtml(item.category_name)} · ${escapeHtml(item.name)}</option>`
        ).join('');
        select.disabled = items.length === 0;
        $('#flow-item-warning').hidden = items.length > 0;
    }

    function renderStructure() {
        const container = $('#structure-lists');
        container.innerHTML = ['asset', 'liability', 'income', 'expense'].map((kind) => {
            const categories = state.categories.filter((category) => category.kind === kind);
            const content = categories.length ? categories.map((category) => {
                const items = state.items.filter((item) => Number(item.category_id) === Number(category.id));
                return `
                    <div class="category-block">
                        <div class="category-name">
                            ${escapeHtml(category.name)}
                            ${Number(category.is_default) ? '<span class="default-badge">standard</span>' : ''}
                        </div>
                        <div class="item-chips">
                            ${items.length
                                ? items.map((item) => `<span class="item-chip">${escapeHtml(item.name)}</span>`).join('')
                                : '<span class="item-chip empty">Ingen poster ennå</span>'}
                        </div>
                    </div>
                `;
            }).join('') : '<div class="empty-state">Ingen kategorier.</div>';

            return `<article class="structure-card"><h2>${kindNames[kind]}</h2>${content}</article>`;
        }).join('');
    }

    async function createCategory(event) {
        event.preventDefault();
        try {
            await api('category', {
                method: 'POST',
                body: {
                    kind: $('#category-kind').value,
                    name: $('#category-name').value.trim(),
                },
            });
            $('#category-name').value = '';
            await loadReferenceData();
            toast('Kategori opprettet.');
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function createItem(event) {
        event.preventDefault();
        const categoryId = Number($('#item-category').value);
        if (!categoryId) {
            toast('Opprett eller velg en kategori først.', 'error');
            return;
        }

        try {
            await api('item', {
                method: 'POST',
                body: {
                    kind: $('#item-kind').value,
                    categoryId,
                    name: $('#item-name').value.trim(),
                },
            });
            $('#item-name').value = '';
            await loadReferenceData();
            await loadSnapshot();
            toast('Post opprettet.');
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function loadSnapshot() {
        if (!state.user) return;
        const date = $('#snapshot-date').value || firstOfMonth();

        try {
            const data = await api('snapshot', { query: { date } });
            $('#snapshot-note').value = data.note || '';
            $('#snapshot-empty').hidden = data.items.length > 0;
            $('#snapshot-status').textContent = data.isComplete
                ? 'Dette er lagret som et komplett snapshot.'
                : (data.items.some((item) => item.amount !== null) ? 'Utkast lagret – ikke markert komplett.' : '');

            const groups = [
                ['asset', 'Eiendeler'],
                ['liability', 'Gjeld'],
            ];

            $('#snapshot-groups').innerHTML = groups.map(([kind, title]) => {
                const items = data.items.filter((item) => item.kind === kind);
                if (!items.length) return '';
                return `
                    <section class="snapshot-group">
                        <h3>${title}</h3>
                        ${items.map((item) => `
                            <div class="snapshot-row">
                                <div class="snapshot-row-copy">
                                    <strong>${escapeHtml(item.name)}</strong>
                                    <small>${escapeHtml(item.category_name)}</small>
                                </div>
                                <label class="money-input">
                                    <input class="snapshot-value" data-item-id="${item.id}" inputmode="decimal"
                                        value="${item.amount === null ? '' : numberInput(item.amount)}"
                                        placeholder="0">
                                    <span>kr</span>
                                </label>
                            </div>
                        `).join('')}
                    </section>
                `;
            }).join('');
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function saveSnapshot(complete) {
        const inputs = $$('.snapshot-value');
        if (!inputs.length) {
            toast('Opprett eiendeler eller gjeldsposter først.', 'error');
            return;
        }

        const values = [];
        for (const input of inputs) {
            const raw = input.value.trim();
            if (complete && raw === '') {
                input.focus();
                toast('Fyll inn verdi for alle poster før du lagrer komplett snapshot.', 'error');
                return;
            }
            if (raw !== '') {
                values.push({ itemId: Number(input.dataset.itemId), amount: raw });
            }
        }

        try {
            await api('snapshot', {
                method: 'POST',
                body: {
                    date: $('#snapshot-date').value,
                    note: $('#snapshot-note').value.trim(),
                    complete,
                    values,
                },
            });
            await Promise.all([loadSnapshot(), loadSummary()]);
            toast(complete ? 'Komplett snapshot lagret.' : 'Utkast lagret.');
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function createFlow(event) {
        event.preventDefault();
        const itemId = Number($('#flow-item').value);
        if (!itemId) {
            toast('Opprett en inntekts- eller utgiftspost først.', 'error');
            return;
        }

        try {
            await api('flow', {
                method: 'POST',
                body: {
                    itemId,
                    date: $('#flow-date').value,
                    amount: $('#flow-amount').value.trim(),
                    note: $('#flow-note').value.trim(),
                },
            });
            $('#flow-amount').value = '';
            $('#flow-note').value = '';
            const month = $('#flow-date').value.slice(0, 7);
            $('#flow-month').value = month;
            await Promise.all([loadFlows(), loadSummary()]);
            toast('Registrering lagret.');
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function loadFlows() {
        if (!state.user) return;
        const month = $('#flow-month').value || currentMonth();
        try {
            const data = await api('flows', { query: { month } });
            const list = $('#flow-list');
            if (!data.entries.length) {
                list.innerHTML = '<div class="empty-state">Ingen registreringer denne måneden.</div>';
                return;
            }

            list.innerHTML = data.entries.map((entry) => `
                <div class="entry-row">
                    <span class="entry-date">${formatDate(entry.entry_date)}</span>
                    <div class="entry-copy">
                        <strong>${escapeHtml(entry.item_name)}</strong>
                        <small>${escapeHtml(entry.category_name)}${entry.note ? ' · ' + escapeHtml(entry.note) : ''}</small>
                    </div>
                    <span class="entry-amount ${entry.kind}">${entry.kind === 'expense' ? '−' : '+'}${fullMoney.format(entry.amount)}</span>
                    <button class="icon-button delete-flow" data-id="${entry.id}" title="Slett" type="button">×</button>
                </div>
            `).join('');

            $$('.delete-flow').forEach((button) => button.addEventListener('click', () => deleteFlow(Number(button.dataset.id))));
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function deleteFlow(id) {
        if (!window.confirm('Slette denne registreringen?')) return;
        try {
            await api('deleteFlow', { method: 'POST', body: { id } });
            await Promise.all([loadFlows(), loadSummary()]);
            toast('Registreringen er slettet.');
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function loadSummary() {
        if (!state.user) return;
        try {
            const data = await api('summary');
            $('#metric-networth').textContent = money.format(data.netWorth);
            $('#metric-assets').textContent = money.format(data.assets);
            $('#metric-liabilities').textContent = money.format(data.liabilities);
            $('#metric-surplus').textContent = money.format(data.surplus);
            $('#overview-income').textContent = money.format(data.income);
            $('#overview-expenses').textContent = money.format(data.expenses);
            $('#metric-cashflow').textContent = `Inntekt ${money.format(data.income)} · Utgift ${money.format(data.expenses)}`;
            $('#metric-snapshot-date').textContent = data.snapshotDate
                ? `Snapshot ${formatDate(data.snapshotDate)}`
                : 'Ingen komplett registrering ennå';
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    function formatDate(iso) {
        if (!iso) return '—';
        const [y, m, d] = iso.split('-');
        return `${d}.${m}.${y}`;
    }

    function numberInput(value) {
        return String(value).replace('.', ',');
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[char]));
    }

    document.addEventListener('DOMContentLoaded', init);
})();
