import { computeDerived, formatCents, parseCents, percent, sum } from './money';

// Live recalculation for the AMP grid (12 months + FY total). Display only.
const form = document.querySelector('[data-amp-grid]');
const def = JSON.parse(document.getElementById('grid-definition')?.textContent || '{"inputs":{}}');

if (form) {
    const inputs = [...form.querySelectorAll('input[data-code]')];
    const rows = [...form.querySelectorAll('tr[data-row]')];
    const isCost = Object.fromEntries(rows.map((r) => [r.dataset.row, r.dataset.cost === '1']));

    const recalc = () => {
        if (!inputs.length) return;
        const v = {};
        for (const i of inputs) {
            const c = parseCents(i.value);
            i.classList.toggle('!border-bad', c === undefined);
            (v[i.dataset.code] ??= {})[i.dataset.month] = c ?? null;
        }
        const totals = {};
        for (let m = 1; m <= 12; m++) {
            const vals = {};
            for (const code of Object.keys(def.inputs)) vals[code] = v[code]?.[m] ?? null;
            const d = computeDerived(def, vals);
            for (const [code, val] of Object.entries(d)) {
                const el = form.querySelector(`[data-derived="${code}"][data-month="${m}"]`);
                if (el) el.textContent = formatCents(val, isCost[code]);
            }
            const gm = form.querySelector(`[data-gm="${m}"]`);
            if (gm) { const p = percent(d.gross_profit, d.total_revenue); gm.textContent = p === null ? '–' : `${p}%`; }
        }
        for (const code of Object.keys(def.inputs)) {
            totals[code] = sum(Array.from({ length: 12 }, (_, k) => v[code]?.[k + 1] ?? null));
        }
        const all = { ...totals, ...computeDerived(def, totals) };
        for (const r of rows) {
            const el = r.querySelector(`[data-total="${r.dataset.row}"]`);
            if (el) el.textContent = formatCents(all[r.dataset.row] ?? null, isCost[r.dataset.row]);
        }
        const gmT = form.querySelector('[data-gm="total"]');
        if (gmT) { const p = percent(all.gross_profit, all.total_revenue); gmT.textContent = p === null ? '–' : `${p}%`; }
    };

    form.addEventListener('input', recalc);
    form.addEventListener('focusout', (e) => {
        if (!e.target.matches?.('input[data-code]')) return;
        const c = parseCents(e.target.value);
        if (c !== undefined) e.target.value = formatCents(c, false, '');
    });
    recalc();
}

document.querySelector('[data-upload-input]')?.addEventListener('change', (e) => {
    if (e.target.files.length) e.target.closest('form').submit();
});
