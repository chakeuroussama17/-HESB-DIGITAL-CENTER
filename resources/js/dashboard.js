import {
    BarController, BarElement, CategoryScale, Chart, Filler, Legend, LinearScale,
    LineController, LineElement, PointElement, RadarController, RadialLinearScale, Tooltip,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, Filler, Legend, LinearScale, LineController,
    LineElement, PointElement, RadarController, RadialLinearScale, Tooltip);

const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const num = (v) => (v === null || v === undefined ? null : Number(v)); // chart geometry only
const fmt = (v, pct) => v === null ? '–' : pct
    ? `${v.toFixed(2)}%`
    : (v < 0 ? `(${Math.abs(v).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })})`
        : v.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

export function themeDefaults() {
    Chart.defaults.font.family = css('--font-sans') || 'Helvetica Neue, Helvetica, Arial, sans-serif';
    Chart.defaults.font.size = 11;
    Chart.defaults.color = css('--ink-muted');
    Chart.defaults.borderColor = css('--line');
}

const charts = [];

function comboChart(root) {
    const id = root.dataset.comboChart;
    const data = JSON.parse(document.querySelector(`[data-chart-data="${id}"]`).textContent);
    let metric = Object.keys(data.metrics)[0];
    const isPct = () => metric === 'gross_margin';

    const datasets = () => {
        const s = data.series[metric];
        return [
            { type: 'bar', label: 'AMP', data: s.amp.map(num), backgroundColor: '#4169E1', borderRadius: 6, maxBarThickness: 24, order: 1 },
            { type: 'bar', label: 'Actual', data: s.actual.map(num), backgroundColor: '#FDB813', borderRadius: 6, maxBarThickness: 24, order: 2 },
        ];
    };

    themeDefaults();
    const chart = new Chart(document.getElementById(id), {
        data: { labels: data.labels, datasets: datasets() },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${fmt(c.parsed.y, isPct())}` } },
            },
            scales: {
                x: { grid: { display: false } },
                y: { grid: { color: css('--line') }, ticks: { callback: (v) => isPct() ? `${v}%` : Number(v).toLocaleString('en-MY') } },
            },
        },
    });
    charts.push(chart);

    root.querySelectorAll('[data-metric]').forEach((btn) => btn.addEventListener('click', () => {
        metric = btn.dataset.metric;
        root.querySelectorAll('[data-metric]').forEach((b) => b.setAttribute('aria-selected', String(b === btn)));
        chart.data.datasets = datasets();
        chart.update();
    }));
}

document.querySelectorAll('[data-combo-chart]').forEach(comboChart);

// Re-colour charts when the theme flips.
document.addEventListener('themechange', () => {
    themeDefaults();
    for (const c of charts) {
        if (c.options.scales?.y?.grid) c.options.scales.y.grid.color = css('--line');
        c.update();
    }
});

export { Chart, charts, css, fmt, num };
