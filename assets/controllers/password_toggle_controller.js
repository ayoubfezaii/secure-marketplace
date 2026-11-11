import { Controller } from '@hotwired/stimulus';

/* Show / hide password. Only switches the input type; the value never leaves the field. */
export default class extends Controller {
    static targets = ['input', 'icon', 'button'];

    toggle() {
        const show = this.inputTarget.type === 'password';
        this.inputTarget.type = show ? 'text' : 'password';
        this.buttonTarget.setAttribute('aria-pressed', String(show));
        this.buttonTarget.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        this.iconTarget.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        this.inputTarget.focus();
    }
}
