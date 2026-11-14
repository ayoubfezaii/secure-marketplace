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
    static targets = ['choice', 'card', 'cash'];
    static values = { cash: String };

    connect() {
        this.toggle();
    }

    toggle() {
        const selected = this.choiceTargets.find((input) => input.checked);
        const cash = selected !== undefined && selected.value === this.cashValue;

        this.cardTargets.forEach((section) => {
            section.hidden = cash;
            // Disabled inputs are not submitted: for a cash order the browser never even sends the card fields.
            section.querySelectorAll('input').forEach((input) => { input.disabled = cash; });
        });
        this.cashTargets.forEach((section) => { section.hidden = !cash; });
    }
}
