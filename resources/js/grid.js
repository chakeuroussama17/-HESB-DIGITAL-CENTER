import { computeDerived, formatCents, parseCents, percent, sum } from './money';

// Live recalculation for the Forecast & Actual grid. Display only: the server
// recomputes everything from the saved inputs.
const form = document.querySelector('[data-grid]');
const def = JSON.parse(document.getElementById('grid-definition')?.textContent || '{"inputs":{}}');

if (form) {
    const inputs = [...form.querySelectorAll('input[data-code]')];
    const rows = [...form.querySelectorAll('tr[data-row]')];
    const isCost = Object.fromEntries(rows.map((r) => [r.dataset.row, r.dataset.cost === '1']));
    const weeks = [...new Set(inputs.map((i) => i.dataset.week))];
    const kinds = ['actual', 'forecast'];
    let dirty = form.querySelector('[data-staged]') !== null; // staged upload counts as unsaved

    const valueOf = (input) => {
        const c = parseCents(input.value);
        input.classList.toggle('!border-bad', c === undefined);
        input.toggleAttribute('aria-invalid', c === undefined);
        return c === undefined ? null : c;
    };

    function recalc() {
        // cells[code][week][kind]
        const cells = {};
        for (const i of inputs) {
            ((cells[i.dataset.code] ??= {})[i.dataset.week] ??= {})[i.dataset.kind] = valueOf(i);
        }

        const totals = {};
        for (const kind of kinds) {
            // Per week column
            for (const w of weeks) {
                const vals = {};
                for (const code of Object.keys(def.inputs)) vals[code] = cells[code]?.[w]?.[kind] ?? null;
                const d = computeDerived(def, vals);
                for (const [code, v] of Object.entries(d)) {
                    const el = form.querySelector(`[data-derived="${code}"][data-week="${w}"][data-kind="${kind}"]`);
                    if (el) el.textContent = formatCents(v, isCost[code]);
                }
            }
            // Totals column
            const tv = {};
            for (const code of Object.keys(def.inputs)) {
                tv[code] = sum(weeks.map((w) => cells[code]?.[w]?.[kind] ?? null));
            }
            totals[kind] = { ...tv, ...computeDerived(def, tv) };
        }

        for (const r of rows) {
            const code = r.dataset.row;
            const act = totals.actual[code] ?? null;
            const fc = totals.forecast[code] ?? null;
            for (const kind of kinds) {
                const el = r.querySelector(`[data-total="${code}"][data-kind="${kind}"]`);
                if (el) el.textContent = formatCents(totals[kind][code] ?? null, isCost[code]);
            }
            const v = act === null && fc === null ? null : (act ?? 0n) - (fc ?? 0n);
            const p = v === null ? null : percent(v, fc);
            const good = v === null ? null : v === 0n ? true : (isCost[code] ? v < 0n : v > 0n);
            for (const [sel, text] of [[`[data-var="${code}"]`, formatCents(v)], [`[data-varpct="${code}"]`, p === null ? '–' : `${p}%`]]) {
                const el = r.querySelector(sel);
                if (!el) continue;
                el.textContent = text;
                el.classList.toggle('text-good', good === true);
                el.classList.toggle('text-bad', good === false);
            }
        }
    }

    function markDirty() {
        dirty = true;
        const label = form.querySelector('[data-dirty-label]');
        if (label) label.textContent = 'Unsaved changes';
    }

    form.addEventListener('input', (e) => {
        if (e.target.matches('input[data-code]')) { markDirty(); recalc(); }
    });

    // Tidy the number on blur: "1234.5" → "1,234.50"
    form.addEventListener('focusout', (e) => {
        const i = e.target;
        if (!i.matches?.('input[data-code]')) return;
        const c = parseCents(i.value);
        if (c !== undefined) i.value = formatCents(c, false, '');
    });

    // Keyboard: Enter / Shift+Enter move down / up in the same column.
    const editable = inputs.filter((i) => !i.disabled);
    const colKey = (i) => `${i.dataset.week}.${i.dataset.kind}`;
    form.addEventListener('keydown', (e) => {
        const i = e.target;
        if (e.key !== 'Enter' || !i.matches('input[data-code]')) return;
        e.preventDefault();
        const col = editable.filter((x) => colKey(x) === colKey(i));
        const next = col[col.indexOf(i) + (e.shiftKey ? -1 : 1)];
        next?.focus();
        next?.select();
    });

    // Paste a block copied from Excel: fills right and down from the focused cell.
    const matrix = (() => {
        const byRow = new Map();
        for (const i of inputs) {
            if (!byRow.has(i.dataset.code)) byRow.set(i.dataset.code, []);
            byRow.get(i.dataset.code).push(i);
        }
        return [...byRow.values()];
    })();
    form.addEventListener('paste', (e) => {
        const target = e.target;
        if (!target.matches('input[data-code]')) return;
        const text = e.clipboardData.getData('text/plain');
        if (!/[\t\n]/.test(text.trim())) return; // single value: normal paste
        e.preventDefault();
        const r0 = matrix.findIndex((row) => row.includes(target));
        const c0 = matrix[r0].indexOf(target);
        let filled = 0;
        text.replace(/\r/g, '').replace(/\n$/, '').split('\n').forEach((line, dr) => {
            line.split('\t').forEach((val, dc) => {
                const cell = matrix[r0 + dr]?.[c0 + dc];
                if (cell && !cell.disabled) { cell.value = val.trim(); filled++; }
            });
        });
        if (filled) { markDirty(); recalc(); }
    });

    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (e) => {
        if (dirty && !form.hasAttribute('data-readonly')) { e.preventDefault(); e.returnValue = ''; }
    });

    recalc();
}

// Upload: submit as soon as a file is chosen.
document.querySelector('[data-upload-input]')?.addEventListener('change', (e) => {
    if (e.target.files.length) e.target.closest('form').submit();
});
