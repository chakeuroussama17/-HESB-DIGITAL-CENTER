import { Chart, charts, css, fmt, num, themeDefaults } from './dashboard';

const data = JSON.parse(document.getElementById('compare-data')?.textContent || 'null');

if (data) {
    themeDefaults();

    charts.push(new Chart(document.getElementById('compare-bar'), {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [
                { label: 'Actual', data: data.actual.map(num), backgroundColor: data.colors, borderRadius: 6, maxBarThickness: 34 },
                { label: 'AMP', data: data.amp.map(num), backgroundColor: css('--ink-muted') + '55', borderRadius: 6, maxBarThickness: 34 },
            ],
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                tooltip: { callbacks: {
                    title: (items) => data.names[items[0].dataIndex],
                    label: (c) => `${c.dataset.label}: ${fmt(c.parsed.y, data.isPercent)}`,
                } },
            },
            scales: {
                x: { grid: { display: false } },
                y: { grid: { color: css('--line') }, ticks: { callback: (v) => data.isPercent ? `${v}%` : Number(v).toLocaleString('en-MY') } },
            },
        },
    }));

    // Fit the radar scale to the data so small gaps between companies stay visible.
    const all = data.radar.series.flatMap((s) => s.data).filter((v) => v !== null).map(Number);
    const rMin = all.length ? Math.max(0, Math.floor((Math.min(...all) - 10) / 10) * 10) : 0;
    const rMax = all.length ? Math.max(110, Math.ceil((Math.max(...all) + 5) / 10) * 10) : 150;

    charts.push(new Chart(document.getElementById('compare-radar'), {
        type: 'radar',
        data: {
            labels: data.radar.axes,
            datasets: data.radar.series.map((s) => ({
                label: s.label,
                data: s.data.map((v) => (v === null ? null : Math.max(rMin, Math.min(rMax, Number(v))))),
                borderColor: s.color, backgroundColor: s.color + '22', pointBackgroundColor: s.color, borderWidth: 2, pointRadius: 3,
            })),
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${c.parsed.r.toFixed(1)}% of AMP` } },
            },
            scales: { r: { min: rMin, max: rMax, ticks: { stepSize: 10, backdropColor: 'transparent' }, grid: { color: css('--line') }, angleLines: { color: css('--line') } } },
        },
    }));
}
