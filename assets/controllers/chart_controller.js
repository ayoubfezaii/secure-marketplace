import { Controller } from '@hotwired/stimulus';
import {
    Chart, BarController, BarElement, LineController, LineElement, PointElement, DoughnutController, ArcElement,
    CategoryScale, LinearScale, Tooltip, Legend, Filler,
} from 'chart.js';
import { formatMoney } from '../money.js';

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, DoughnutController, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Filler);

const PALETTE = ['#141414', '#E8701A', '#8B7E74', '#2E7D5B', '#3B6E8F', '#D4A017', '#C0392B', '#B9A898'];

/*
 * Generic chart (line / bar / doughnut).
 *
 * SECURITY (rules 2, 13): the data comes from a data-chart-config-value attribute: JSON built by the server and
 * HTML-escaped by Twig, parsed by Stimulus with JSON.parse. No inline <script>, no eval, nothing executable, so
 * the CSP keeps script-src 'self'. Labels are drawn on a canvas as text.
 *
 * Money: a dataset flagged "money: true" by the server is an amount in dinars. Its axis ticks and its tooltips are
 * formatted like everywhere else on the site ("1 250,500 DT").
 */
export default class extends Controller {
    static values = { config: Object };

    connect() {
        const cfg = this.configValue || {};
        const type = ['line', 'bar', 'doughnut'].includes(cfg.type) ? cfg.type : 'line';
        const canvas = document.createElement('canvas');
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', cfg.title || 'Chart');
        this.element.replaceChildren(canvas);

        Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
        Chart.defaults.color = '#6F645B';

        // Which datasets (and which axes) carry money amounts.
        const source = cfg.datasets || [];
        const isMoney = (index) => source[index] !== undefined && source[index].money === true;
        const moneyAxis = (axis) => source.some((ds) => ds.money === true && (ds.axis === 'y1' ? 'y1' : 'y') === axis);
        const moneyTicks = (axis) => (moneyAxis(axis) ? { callback: (value) => formatMoney(value) } : { precision: 0 });

        const datasets = source.map((ds, i) => {
            const color = PALETTE[i % PALETTE.length];
            if (type === 'doughnut') {
                return { label: ds.label, data: ds.data, backgroundColor: PALETTE, borderColor: '#fff', borderWidth: 2 };
            }
            if (type === 'line') {
                return {
                    label: ds.label, data: ds.data, borderColor: color, backgroundColor: i === 0 ? 'rgba(232, 112, 26, .12)' : 'transparent',
                    fill: i === 0, tension: 0.35, pointRadius: 0, pointHoverRadius: 4, borderWidth: 2, yAxisID: ds.axis === 'y1' ? 'y1' : 'y',
                };
            }
            return { label: ds.label, data: ds.data, backgroundColor: i === 0 ? '#141414' : '#E8701A', borderRadius: 6, maxBarThickness: 28, yAxisID: ds.axis === 'y1' ? 'y1' : 'y' };
        });

        const hasY1 = datasets.some((d) => d.yAxisID === 'y1');
        const options = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: type === 'doughnut' || datasets.length > 1, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const raw = type === 'doughnut' ? ctx.parsed : ctx.parsed.y;
                            const name = type === 'doughnut' ? ctx.label : ctx.dataset.label;

                            return `${name}: ${isMoney(ctx.datasetIndex) ? formatMoney(raw) : raw}`;
                        },
                    },
                },
            },
        };
        if (type !== 'doughnut') {
            options.scales = {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
                y: { beginAtZero: true, grid: { color: '#EFE8DF' }, ticks: moneyTicks('y') },
            };
            if (hasY1) {
                options.scales.y1 = { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: moneyTicks('y1') };
            }
        } else {
            options.cutout = '68%';
        }

        this.chart = new Chart(canvas, { type, data: { labels: cfg.labels || [], datasets }, options });
    }

    disconnect() {
        this.chart?.destroy();
    }
}
