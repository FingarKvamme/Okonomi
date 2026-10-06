(() => {
    'use strict';

    const cfg = window.APP_CONFIG || {};
    const $ = (selector) => document.querySelector(selector);

    const state = {
        workbook: null,
        rows: [],
        columns: [],
        csrf: null,
        categories: [],
        items: [],
        invalidDates: 0,
        invalidAmounts: 0,
    };

    const monthMap = {
        jan: 1, januar: 1,
        feb: 2, februar: 2,
        mar: 3, mars: 3,
        apr: 4, april: 4,
        mai: 5,
        jun: 6, juni: 6,
        jul: 7, juli: 7,
        aug: 8, august: 8,
        sep: 9, sept: 9, september: 9,
        okt: 10, oktober: 10,
        nov: 11, november: 11,
        des: 12, desember: 12,
    };

    function toast(message, type = 'ok') {
        const node = $('#toast');
        if (!node) return;
        node.textContent = message;
        node.classList.toggle('error', type === 'error');
        node.classList.add('show');
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => node.classList.remove('show'), 3600);
    }

    function apiUrl(action) {
        const url = new URL(cfg.apiUrl, window.location.href);
        url.searchParams.set('action', action);
        return url;
    }

    async function api(action, options = {}) {
        const method = options.method || 'GET';
        const headers = { ...(options.headers || {}) };

        if (method !== 'GET') {
            if (!state.csrf) {
                const me = await api('me');
                state.csrf = me.csrf;
            }
            headers['X-CSRF-Token'] = state.csrf || '';
            headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(apiUrl(action), {
            method,
            credentials: 'same-origin',
            headers,
            body: options.body === undefined ? undefined : JSON.stringify(options.body),
        });

        let data = {};
        try {
            data = await response.json();
        } catch (_) {
            data = { error: 'Serveren ga et ugyldig svar.' };
        }

        if (!response.ok) {
            throw new Error(data.error || 'Importen mislyktes.');
        }
        return data;
    }

    function cleanHeader(value, index) {
        const text = String(value ?? '').replace(/\u00a0/g, ' ').trim();
        return text || `Kolonne ${index + 1}`;
    }

    function isoDate(year, month, day = 1) {
        const d = new Date(year, month - 1, day);
        if (
            d.getFullYear() !== year ||
            d.getMonth() !== month - 1 ||
            d.getDate() !== day
        ) return null;

        return [
            String(year).padStart(4, '0'),
            String(month).padStart(2, '0'),
            String(day).padStart(2, '0'),
        ].join('-');
    }

    function normalizeYear(value) {
        const year = Number(value);
        if (!Number.isInteger(year)) return null;
        if (year < 100) return year <= 69 ? 2000 + year : 1900 + year;
        return year;
    }

    function parseExcelDate(value) {
        if (value instanceof Date && !Number.isNaN(value.getTime())) {
            return isoDate(value.getFullYear(), value.getMonth() + 1, value.getDate());
        }

        if (typeof value === 'number' && Number.isFinite(value) && window.XLSX?.SSF) {
            const parsed = XLSX.SSF.parse_date_code(value);
            if (parsed?.y && parsed?.m && parsed?.d) {
                return isoDate(parsed.y, parsed.m, parsed.d);
            }
        }

        let text = String(value ?? '')
            .replace(/\u00a0/g, ' ')
            .trim()
            .toLowerCase();

        if (!text) return null;
        text = text.replace(/,/g, ' ').replace(/\s+/g, ' ').trim();

        let match = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (match) return isoDate(Number(match[1]), Number(match[2]), Number(match[3]));

        match = text.match(/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})$/);
        if (match) {
            return isoDate(normalizeYear(match[3]), Number(match[2]), Number(match[1]));
        }

        match = text.match(/^([a-zæøå]+)\.?\s+(\d{2,4})$/i);
        if (match) {
            const month = monthMap[match[1].replace('.', '')];
            const year = normalizeYear(match[2]);
            return month && year ? isoDate(year, month, 1) : null;
        }

        match = text.match(/^(\d{1,2})\.?\s+([a-zæøå]+)\.?\s+(\d{2,4})$/i);
        if (match) {
            const month = monthMap[match[2].replace('.', '')];
            const year = normalizeYear(match[3]);
            return month && year ? isoDate(year, month, Number(match[1])) : null;
        }

        return null;
    }

    function parseAmount(value) {
        if (value === null || value === undefined) return null;
        if (typeof value === 'number') {
            return Number.isFinite(value) ? value : null;
        }

        let text = String(value)
            .replace(/\u00a0/g, ' ')
            .replace(/−/g, '-')
            .trim();

        if (!text || text === '-' || text === '—') return null;

        let negativeByParens = false;
        if (/^\(.*\)$/.test(text)) {
            negativeByParens = true;
            text = text.slice(1, -1);
        }

        text = text
            .replace(/\bNOK\b/gi, '')
            .replace(/kr/gi, '')
            .replace(/'/g, '')
            .replace(/\s+/g, '');

        if (!text) return null;

        if (text.includes(',')) {
            text = text.replace(/\./g, '').replace(',', '.');
        } else if (/^-?\d{1,3}(\.\d{3})+$/.test(text)) {
            text = text.replace(/\./g, '');
        }

        if (!/^-?\d+(\.\d+)?$/.test(text)) return Number.NaN;

        let number = Number(text);
        if (!Number.isFinite(number)) return Number.NaN;
        if (negativeByParens) number = -Math.abs(number);
        return number;
    }

    function todayIso() {
        const d = new Date();
        return isoDate(d.getFullYear(), d.getMonth() + 1, d.getDate());
    }

    async function loadReferenceData() {
        const [me, categories, items] = await Promise.all([
            api('me'),
            api('categories'),
            api('items'),
        ]);
        if (!me.authenticated) throw new Error('Du må være logget inn for å importere.');
        state.csrf = me.csrf;
        state.categories = categories.categories || [];
        state.items = items.items || [];
    }

    async function onFileSelected() {
        const file = $('#import-file')?.files?.[0];
        if (!file) return;

        if (!window.XLSX) {
            toast('Excel-biblioteket kunne ikke lastes. Last siden på nytt.', 'error');
            return;
        }

        try {
            const buffer = await file.arrayBuffer();
            state.workbook = XLSX.read(buffer, {
                type: 'array',
                cellDates: true,
                raw: true,
            });

            const sheetSelect = $('#import-sheet');
            sheetSelect.innerHTML = state.workbook.SheetNames
                .map((name) => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`)
                .join('');
            sheetSelect.disabled = false;
            parseSelectedSheet();
        } catch (error) {
            toast('Kunne ikke lese Excel-filen: ' + error.message, 'error');
        }
    }

    function parseSelectedSheet() {
        if (!state.workbook) return;

        const sheetName = $('#import-sheet').value || state.workbook.SheetNames[0];
        const sheet = state.workbook.Sheets[sheetName];
        const matrix = XLSX.utils.sheet_to_json(sheet, {
            header: 1,
            raw: true,
            defval: null,
            blankrows: false,
        });

        if (!matrix.length || !Array.isArray(matrix[0]) || matrix[0].length < 2) {
            toast('Arket må ha en datokolonne og minst én datakolonne.', 'error');
            $('#import-preview').hidden = true;
            return;
        }

        const headerRow = matrix[0];
        state.columns = headerRow.slice(1).map((header, idx) => ({
            key: 'c' + (idx + 1),
            sourceIndex: idx + 1,
            sourceName: cleanHeader(header, idx + 1),
        }));

        state.rows = [];
        state.invalidDates = 0;
        state.invalidAmounts = 0;

        for (const rawRow of matrix.slice(1)) {
            const hasAnyValue = rawRow.some((cell) => cell !== null && cell !== undefined && String(cell).trim() !== '');
            if (!hasAnyValue) continue;

            const date = parseExcelDate(rawRow[0]);
            const hasDataValues = rawRow.slice(1).some((cell) => cell !== null && cell !== undefined && String(cell).trim() !== '');

            if (!date) {
                if (hasDataValues) state.invalidDates += 1;
                continue;
            }

            const values = {};
            for (const column of state.columns) {
                const rawValue = rawRow[column.sourceIndex];
                const parsed = parseAmount(rawValue);
                if (Number.isNaN(parsed)) {
                    if (rawValue !== null && rawValue !== undefined && String(rawValue).trim() !== '') {
                        state.invalidAmounts += 1;
                    }
                    continue;
                }
                if (parsed !== null) values[column.key] = parsed;
            }

            state.rows.push({ date, values });
        }

        if (!state.rows.length) {
            toast('Fant ingen rader med gyldig dato og tall.', 'error');
            $('#import-preview').hidden = true;
            return;
        }

        state.rows.sort((a, b) => a.date.localeCompare(b.date));
        const minDate = state.rows[0].date;
        const maxDate = state.rows[state.rows.length - 1].date;
        const today = todayIso();
        const defaultTo = maxDate <= today ? maxDate : (minDate <= today ? today : maxDate);

        $('#import-from').value = minDate;
        $('#import-to').value = defaultTo;
        $('#import-preview').hidden = false;

        renderMapping();
        updatePreview();
    }

    function selectedKind() {
        return $('#import-kind')?.value || 'asset';
    }

    function categoryCandidates() {
        const kind = selectedKind();
        return state.categories.filter((category) => category.kind === kind);
    }

    function findCategoryByName(name) {
        const normalized = String(name).toLocaleLowerCase('nb-NO');
        return categoryCandidates().find(
            (category) => String(category.name).toLocaleLowerCase('nb-NO') === normalized
        );
    }

    function heuristicCategory(columnName, kind) {
        const text = String(columnName).toLocaleLowerCase('nb-NO');

        if (kind === 'asset') {
            if (/skue|dnb|bank|konto|bsu/.test(text)) return findCategoryByName('Bankkonto');
            if (/nordnet|ask|ikz|vps|ips|aksj|fond|selskap|etoro/.test(text)) return findCategoryByName('Aksjer');
            if (/firi|guarda|krypto|bitcoin|btc|eth/.test(text)) return findCategoryByName('Krypto');
            if (/eiendom|bolig|hus|leilig/.test(text)) return findCategoryByName('Eiendom');
            if (/sølv|gull|råvare/.test(text)) return findCategoryByName('Råvarer');
            if (/lån|utlån/.test(text)) return findCategoryByName('Utlån');
        }

        if (kind === 'liability') {
            if (/bolig|pant/.test(text)) return findCategoryByName('Boliglån');
            if (/studie|lånekassen/.test(text)) return findCategoryByName('Studielån');
            return findCategoryByName('Privatlån');
        }

        if (kind === 'income') {
            if (/lønn|lonn|salary/.test(text)) return findCategoryByName('Lønn');
            if (/utbytte|rente|kapital/.test(text)) return findCategoryByName('Kapitalinntekt');
            if (/nav|ytelse/.test(text)) return findCategoryByName('Ytelser');
            return findCategoryByName('Annen inntekt');
        }

        if (kind === 'expense') {
            if (/diesel|bensin|bil|buss|tog|transport/.test(text)) return findCategoryByName('Transport');
            if (/mat|rema|kiwi|meny|coop/.test(text)) return findCategoryByName('Mat');
            if (/hus|bolig|leie|strøm|strom/.test(text)) return findCategoryByName('Bolig');
            if (/forsik/.test(text)) return findCategoryByName('Forsikring');
            if (/netflix|spotify|abonnement/.test(text)) return findCategoryByName('Abonnement');
            if (/skatt|avgift/.test(text)) return findCategoryByName('Skatt og avgifter');
            if (/lege|apotek|helse/.test(text)) return findCategoryByName('Helse');
            if (/fritid|kino|reise/.test(text)) return findCategoryByName('Fritid');
            return findCategoryByName('Annet');
        }

        return null;
    }

    function valueCountForColumn(key) {
        const from = $('#import-from').value;
        const to = $('#import-to').value;
        return state.rows.filter((row) =>
            row.date >= from &&
            row.date <= to &&
            Object.prototype.hasOwnProperty.call(row.values, key)
        ).length;
    }

    function renderMapping() {
        const container = $('#import-mapping');
        const categories = categoryCandidates();
        const kind = selectedKind();

        if (!categories.length) {
            container.innerHTML = '<div class="empty-state">Ingen kategorier finnes for denne typen.</div>';
            $('#import-submit').disabled = true;
            return;
        }

        $('#import-submit').disabled = false;

        container.innerHTML = state.columns.map((column) => {
            const existing = state.items.find((item) =>
                item.kind === kind &&
                String(item.name).toLocaleLowerCase('nb-NO') === column.sourceName.toLocaleLowerCase('nb-NO')
            );
            const suggested = existing
                ? categories.find((category) => Number(category.id) === Number(existing.category_id))
                : heuristicCategory(column.sourceName, kind);
            const categoryId = Number(suggested?.id || categories[0]?.id || 0);
            const count = valueCountForColumn(column.key);

            const options = categories.map((category) =>
                `<option value="${category.id}" ${Number(category.id) === categoryId ? 'selected' : ''}>${escapeHtml(category.name)}</option>`
            ).join('');

            return `
                <div class="import-map-row" data-key="${column.key}">
                    <label class="import-check">
                        <input class="import-column-enabled" type="checkbox" ${count > 0 ? 'checked' : ''}>
                    </label>
                    <div class="field import-name-field">
                        <span>Post</span>
                        <input class="import-column-name" maxlength="160" value="${escapeHtml(column.sourceName)}">
                    </div>
                    <label class="field">
                        <span>Kategori</span>
                        <select class="import-column-category">${options}</select>
                    </label>
                    <div class="import-count"><strong class="import-column-count">${count}</strong><span>verdier</span></div>
                </div>
            `;
        }).join('');
    }

    function updatePreview() {
        const from = $('#import-from').value;
        const to = $('#import-to').value;

        $$('.import-map-row').forEach((row) => {
            const key = row.dataset.key;
            const count = valueCountForColumn(key);
            const target = row.querySelector('.import-column-count');
            if (target) target.textContent = String(count);
        });

        const filtered = state.rows.filter((row) => row.date >= from && row.date <= to);
        const valueCount = filtered.reduce(
            (sum, row) => sum + Object.keys(row.values).length,
            0
        );

        const warnings = [];
        if (state.invalidDates) warnings.push(`${state.invalidDates} rader med ugyldig dato hoppes over`);
        if (state.invalidAmounts) warnings.push(`${state.invalidAmounts} celler med ugyldig beløp hoppes over`);

        $('#import-summary').innerHTML = `
            <strong>${filtered.length} datoer</strong>
            <span>${valueCount} utfylte celler i valgt periode</span>
            ${warnings.length ? `<small>${escapeHtml(warnings.join(' · '))}</small>` : ''}
        `;

        const enabledRows = $$('.import-map-row')
            .filter((row) => row.querySelector('.import-column-enabled')?.checked)
            .slice(0, 6);
        const enabledKeys = enabledRows.map((row) => row.dataset.key);
        const nameMap = Object.fromEntries(enabledRows.map((row) => [
            row.dataset.key,
            row.querySelector('.import-column-name')?.value || row.dataset.key,
        ]));

        const sampleRows = filtered.slice(0, 5);
        if (!sampleRows.length) {
            $('#import-sample').innerHTML = '<div class="empty-state">Ingen rader i valgt datointervall.</div>';
            return;
        }

        $('#import-sample').innerHTML = `
            <div class="import-table-wrap">
                <table class="import-table">
                    <thead>
                        <tr>
                            <th>Dato</th>
                            ${enabledKeys.map((key) => `<th>${escapeHtml(nameMap[key])}</th>`).join('')}
                        </tr>
                    </thead>
                    <tbody>
                        ${sampleRows.map((row) => `
                            <tr>
                                <td>${escapeHtml(row.date)}</td>
                                ${enabledKeys.map((key) => `<td>${Object.prototype.hasOwnProperty.call(row.values, key) ? escapeHtml(String(row.values[key])) : '—'}</td>`).join('')}
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            <small class="muted">Forhåndsvisning viser inntil 5 rader og 6 valgte kolonner.</small>
        `;
    }

    async function submitImport() {
        const from = $('#import-from').value;
        const to = $('#import-to').value;
        if (!from || !to || from > to) {
            toast('Velg et gyldig datointervall.', 'error');
            return;
        }

        const mappings = $$('.import-map-row')
            .filter((row) => row.querySelector('.import-column-enabled')?.checked)
            .map((row) => ({
                key: row.dataset.key,
                name: row.querySelector('.import-column-name')?.value.trim(),
                categoryId: Number(row.querySelector('.import-column-category')?.value || 0),
            }))
            .filter((mapping) => mapping.key && mapping.name && mapping.categoryId);

        if (!mappings.length) {
            toast('Velg minst én kolonne som skal importeres.', 'error');
            return;
        }

        const selectedKeys = new Set(mappings.map((mapping) => mapping.key));
        const rows = state.rows
            .filter((row) => row.date >= from && row.date <= to)
            .map((row) => {
                const values = {};
                for (const [key, value] of Object.entries(row.values)) {
                    if (selectedKeys.has(key)) values[key] = value;
                }
                return { date: row.date, values };
            })
            .filter((row) => Object.keys(row.values).length > 0);

        if (!rows.length) {
            toast('Ingen verdier å importere i valgt periode.', 'error');
            return;
        }

        const button = $('#import-submit');
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Importerer…';

        try {
            const result = await api('importData', {
                method: 'POST',
                body: {
                    kind: selectedKind(),
                    columns: mappings,
                    rows,
                },
            });

            const pieces = [
                `${result.valuesImported || 0} verdier importert`,
                `${result.itemsCreated || 0} nye poster opprettet`,
            ];
            if (result.duplicatesSkipped) pieces.push(`${result.duplicatesSkipped} duplikater hoppet over`);
            toast(pieces.join(' · '));

            setTimeout(() => window.location.reload(), 1100);
        } catch (error) {
            toast(error.message, 'error');
            button.disabled = false;
            button.textContent = originalText;
        }
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

    async function init() {
        if (!$('#import-file')) return;

        try {
            await loadReferenceData();
        } catch (_) {
            return;
        }

        $('#import-file').addEventListener('change', onFileSelected);
        $('#import-sheet').addEventListener('change', parseSelectedSheet);
        $('#import-kind').addEventListener('change', () => {
            if (!state.rows.length) return;
            renderMapping();
            updatePreview();
        });
        $('#import-from').addEventListener('change', updatePreview);
        $('#import-to').addEventListener('change', updatePreview);
        $('#import-mapping').addEventListener('change', updatePreview);
        $('#import-mapping').addEventListener('input', updatePreview);
        $('#import-submit').addEventListener('click', submitImport);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
