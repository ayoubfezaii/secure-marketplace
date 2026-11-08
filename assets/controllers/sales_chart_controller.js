import { Controller } from '@hotwired/stimulus';
import { Chart, BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js';

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend);

/*
 * Seller sales chart.
 *
 * SECURITY (rules 2, 13): the data comes from a data-* attribute (JSON, HTML-escaped by Twig), parsed with
 * JSON.parse by Stimulus. There is no inline <script> and no user text injected into JavaScript, so the CSP can
 * keep script-src 'self', and nothing in the data can be executed.
 */
export default class extends Controller {
    static values = { data: Object };

    connect() {
        const canvas = document.createElement('canvas');
        canvas.setAttribute('aria-label', 'Sales over the last 30 days');
        canvas.setAttribute('role', 'img');
        this.element.replaceChildren(canvas);

        const { labels = [], revenue = [], units = [] } = this.dataValue;

        this.chart = new Chart(canvas, {
            data: {
                labels,
                datasets: [
                    { type: 'bar', label: 'Revenue (€)', data: revenue, backgroundColor: 'rgba(13, 110, 253, 0.6)', yAxisID: 'y' },
                    { type: 'line', label: 'Units sold', data: units, borderColor: '#198754', backgroundColor: '#198754', tension: 0.3, yAxisID: 'y1' },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { beginAtZero: true, position: 'left', title: { display: true, text: '€' } },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 } },
                },
            },
        });
    }

    disconnect() {
        this.chart?.destroy();
    }
}
