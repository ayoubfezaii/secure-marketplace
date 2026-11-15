// FILE: assets/controllers/password_strength_controller.js
// WHAT: Stimulus controller "password-strength": coloured bar that tells how strong the typed password is.
// ROLE IN THE PROJECT: helps the user choose a good password. It is only a hint: the server decides.
// USED BY: registration, password reset and change-password forms (visitor and logged-in users).

import { Controller } from '@hotwired/stimulus';

/*
 * Password strength meter (UX only). The real policy (12+ chars, letter, digit, not in a breach list) is enforced
 * on the server by App\Security\PasswordPolicy (rule 15). Nothing is sent anywhere from here.
 */
export default class extends Controller {
    // "targets": the password field, the bar and the text under it.
    static targets = ['input', 'meter', 'label'];

    // connect(): shows the initial state.
    connect() {
        this.update();
    }

    // update(): called on each key press. Gives a score from 0 to 4.
    update() {
        const v = this.inputTarget.value || '';
        let score = 0;
        // One point each: 12+ characters, lower AND upper case, a digit, a symbol (or 16+ characters).
        if (v.length >= 12) score++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v) || v.length >= 16) score++;
        // Shorter than 12 characters is never better than "Too weak"; empty = 0.
        if (v.length > 0 && v.length < 12) score = Math.min(score, 1);
        if (v.length === 0) score = 0;

        // Write the score on the bar (the CSS colours it) and the label under it.
        const labels = ['', 'Too weak', 'Fair', 'Good', 'Strong'];
        this.meterTarget.dataset.score = String(score);
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = v.length ? labels[score] : 'At least 12 characters, with letters and numbers.';
        }
    }
}
