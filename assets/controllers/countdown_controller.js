// FILE: assets/controllers/countdown_controller.js
// WHAT: Stimulus controller "countdown": shows the time left before the end of the promotion.
// ROLE IN THE PROJECT: decoration of the home page. The end date is decided by the server (HomeController).
// USED BY: templates/home/index.html.twig (everyone).

import { Controller } from '@hotwired/stimulus';

/* Promo countdown. The end date comes from the server in a data attribute (ISO 8601). */
export default class extends Controller {
    // "values" = data from the HTML (data-countdown-end-value). "targets" = the four numbers to update.
    static values = { end: String };
    static targets = ['days', 'hours', 'minutes', 'seconds'];

    // connect(): reads the end date and starts a timer that calls tick() every second.
    connect() {
        this.end = Date.parse(this.endValue);
        // Invalid date: do nothing.
        if (Number.isNaN(this.end)) {
            return;
        }
        this.tick();
        this.timer = window.setInterval(() => this.tick(), 1000);
    }

    // disconnect(): stops the timer when the element leaves the page.
    disconnect() {
        window.clearInterval(this.timer);
    }

    // tick(): computes the remaining days / hours / minutes / seconds and writes them in the page.
    tick() {
        // Seconds left (never negative).
        let s = Math.max(0, Math.floor((this.end - Date.now()) / 1000));
        const d = Math.floor(s / 86400); s -= d * 86400;
        const h = Math.floor(s / 3600); s -= h * 3600;
        const m = Math.floor(s / 60); s -= m * 60;
        // Always show two digits ("07").
        const pad = (n) => String(n).padStart(2, '0');
        this.daysTarget.textContent = pad(d);
        this.hoursTarget.textContent = pad(h);
        this.minutesTarget.textContent = pad(m);
        this.secondsTarget.textContent = pad(s);
    }
}
