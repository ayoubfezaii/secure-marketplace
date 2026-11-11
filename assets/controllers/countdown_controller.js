import { Controller } from '@hotwired/stimulus';

/* Promo countdown. The end date comes from the server in a data attribute (ISO 8601). */
export default class extends Controller {
    static values = { end: String };
    static targets = ['days', 'hours', 'minutes', 'seconds'];

    connect() {
        this.end = Date.parse(this.endValue);
        if (Number.isNaN(this.end)) {
            return;
        }
        this.tick();
        this.timer = window.setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        window.clearInterval(this.timer);
    }

    tick() {
        let s = Math.max(0, Math.floor((this.end - Date.now()) / 1000));
        const d = Math.floor(s / 86400); s -= d * 86400;
        const h = Math.floor(s / 3600); s -= h * 3600;
        const m = Math.floor(s / 60); s -= m * 60;
        const pad = (n) => String(n).padStart(2, '0');
        this.daysTarget.textContent = pad(d);
        this.hoursTarget.textContent = pad(h);
        this.minutesTarget.textContent = pad(m);
        this.secondsTarget.textContent = pad(s);
    }
}
