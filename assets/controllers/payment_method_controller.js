// FILE: assets/controllers/payment_method_controller.js
// WHAT: Stimulus controller "payment-method": shows the card fields or the cash-on-delivery text.
// ROLE IN THE PROJECT: display helper of the checkout payment step.
// USED BY: templates/checkout/payment.html.twig (buyer).

import { Controller } from '@hotwired/stimulus';

/*
 * Checkout payment step: shows the card form only when "Pay by card" is selected.
 *
 * Display only. SECURITY: this controller decides nothing. The server whitelists the payment method (PHP enum),
 * validates the card fields whenever the method is "card", ignores them for cash on delivery, and enforces the
 * cash-on-delivery limits itself. With JavaScript disabled both sections stay visible and the form still works.
 * No inline script or style: sections are toggled with the "hidden" attribute (CSP friendly, rule 13).
 */
export default class extends Controller {
    // "targets": the radio buttons ("choice"), the card section and the cash section.
    // "values": data-payment-method-cash-value = the value of the "cash on delivery" radio button.
    static targets = ['choice', 'card', 'cash'];
    static values = { cash: String };

    // connect(): applies the right display when the page opens.
    connect() {
        this.toggle();
    }

    // toggle(): called when another radio button is chosen.
    toggle() {
        // Which radio button is checked? Is it cash on delivery?
        const selected = this.choiceTargets.find((input) => input.checked);
        const cash = selected !== undefined && selected.value === this.cashValue;

        // Card sections: hidden for cash.
        this.cardTargets.forEach((section) => {
            section.hidden = cash;
            // Disabled inputs are not submitted: for a cash order the browser never even sends the card fields.
            section.querySelectorAll('input').forEach((input) => { input.disabled = cash; });
        });
        // Cash sections: shown only for cash.
        this.cashTargets.forEach((section) => { section.hidden = !cash; });
    }
}
