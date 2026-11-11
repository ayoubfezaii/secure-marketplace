import { Controller } from '@hotwired/stimulus';

/* +/- quantity stepper. Bounds are UX only: the server clamps 1..99 and checks stock again (rules 10, 15). */
export default class extends Controller {
    static targets = ['input'];
    static values = { autosubmit: Boolean };

    inc() { this.change(1); }
    dec() { this.change(-1); }

    change(delta) {
        const input = this.inputTarget;
        const min = parseInt(input.min || '1', 10);
        const max = parseInt(input.max || '99', 10);
        const current = parseInt(input.value || '1', 10) || min;
        const next = Math.min(max, Math.max(min, current + delta));
        if (next === current) {
            return;
        }
        input.value = String(next);
        if (this.autosubmitValue && input.form) {
            input.form.requestSubmit();
        }
    }
}
