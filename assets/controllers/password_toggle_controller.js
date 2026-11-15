// FILE: assets/controllers/password_toggle_controller.js
// WHAT: Stimulus controller "password-toggle": the "eye" button that shows or hides the typed password.
// ROLE IN THE PROJECT: comfort for the user on password fields.
// USED BY: login, registration and password forms (everyone).

import { Controller } from '@hotwired/stimulus';

/* Show / hide password. Only switches the input type; the value never leaves the field. */
export default class extends Controller {
    // "targets": the password field, the eye icon and the button.
    static targets = ['input', 'icon', 'button'];

    // toggle(): switches the field between type="password" (dots) and type="text" (readable).
    toggle() {
        const show = this.inputTarget.type === 'password';
        this.inputTarget.type = show ? 'text' : 'password';
        // Update the accessibility texts and the icon, then give the focus back to the field.
        this.buttonTarget.setAttribute('aria-pressed', String(show));
        this.buttonTarget.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        this.iconTarget.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        this.inputTarget.focus();
    }
}
