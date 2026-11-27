// Money in the browser: BigInt cents, mirroring App\Support\Money (no floats).

export function parseCents(raw) {
    if (raw == null) return null;
    let s = String(raw).trim();
    if (s === '' || s === '-') return null;
    let neg = false;
    const m = s.match(/^\((.*)\)$/);
    if (m) { neg = true; s = m[1].trim(); }
    s = s.replace(/^RM/i, '').replace(/[,\s ]/g, '');
    if (s.startsWith('-')) { neg = !neg; s = s.slice(1); }
    if (!/^(\d+(\.\d+)?|\.\d+)$/.test(s)) return undefined; // invalid
    const [int, dec = ''] = s.split('.');
    let cents = BigInt(int || '0') * 100n + BigInt((dec + '00').slice(0, 2));
    if ((dec[2] ?? '0') >= '5') cents += 1n; // half away from zero
    return neg ? -cents : cents;
}

export function formatCents(c, asCost = false, blank = '–') {
    if (c === null || c === undefined) return blank;
    const neg = c < 0n;
    const abs = neg ? -c : c;
    const int = (abs / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const out = `${int}.${(abs % 100n).toString().padStart(2, '0')}`;
    const bracket = asCost ? (!neg && c !== 0n) : neg;
    return bracket ? `(${out})` : out;
}

export function sum(values) {
    const present = values.filter((v) => v !== null && v !== undefined);
    return present.length ? present.reduce((a, b) => a + b, 0n) : null;
}

/** a / |b| × 100, as a string with 2 dp, half away from zero. */
export function percent(a, b) {
    if (a === null || b === null || b === 0n) return null;
    const absB = b < 0n ? -b : b;
    const num = a * 10000n;
    const sign = num < 0n ? -1n : 1n;
    const q = (2n * num + sign * absB) / (2n * absB); // hundredths of a percent
    const neg = q < 0n;
    const abs = neg ? -q : q;
    return `${neg ? '-' : ''}${abs / 100n}.${(abs % 100n).toString().padStart(2, '0')}`;
}

/**
 * Same rules as App\Services\Finance\FinancialCalculator::compute().
 * def = { inputs: {code: group}, derived: [...] }, values = {code: cents|null}
 */
export function computeDerived(def, values) {
    const groups = { revenue: [], cost_of_sales: [], opex: [], other: [], finance: [] };
    let any = false;
    for (const [code, group] of Object.entries(def.inputs)) {
        const v = values[code];
        if (v !== null && v !== undefined) { any = true; groups[group]?.push(v); }
    }
    if (!any) return { total_revenue: null, cost_of_sales: null, gross_profit: null, pbt: null };
    const s = (g) => groups[g].reduce((a, b) => a + b, 0n);
    const revenue = s('revenue');
    const cos = s('cost_of_sales');
    const gp = revenue - cos;
    return { total_revenue: revenue, cost_of_sales: cos, gross_profit: gp, pbt: gp - s('opex') + s('other') - s('finance') };
}
