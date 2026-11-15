// FILE: assets/controllers/quantity_controller.js
// WHAT: Stimulus controller "quantity": the - and + buttons around a quantity field.
// ROLE IN THE PROJECT: comfort on the product page and in the cart.
// USED BY: templates/catalog/show.html.twig and templates/cart/index.html.twig (buyer).

import { Controller } from '@hotwired/stimulus';

/* +/- quantity stepper. Bounds are UX only: the server clamps 1..99 and checks stock again (rules 10, 15). */
export default class extends Controller {
    // "targets": the number field. "values": autosubmit = send the form after each change (used in the cart).
    static targets = ['input'];
    static values = { autosubmit: Boolean };

    // inc() / dec(): called by the + and - buttons.
    inc() { this.change(1); }
    dec() { this.change(-1); }

    // change(delta): adds delta to the quantity, staying between the min and max of the field.
    change(delta) {
        const input = this.inputTarget;
        const min = parseInt(input.min || '1', 10);
        const max = parseInt(input.max || '99', 10);
        const current = parseInt(input.value || '1', 10) || min;
        const next = Math.min(max, Math.max(min, current + delta));
        // Already at the limit: nothing changes.
        if (next === current) {
            return;
        }
        input.value = String(next);
        // In the cart, send the form so the server saves the new quantity.
        if (this.autosubmitValue && input.form) {
            input.form.requestSubmit();
        }
    }
}
