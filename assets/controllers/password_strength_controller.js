import { Controller } from '@hotwired/stimulus';

/*
 * Password strength meter (UX only). The real policy (12+ chars, letter, digit, not in a breach list) is enforced
 * on the server by App\Security\PasswordPolicy (rule 15). Nothing is sent anywhere from here.
 */
export default class extends Controller {
    static targets = ['input', 'meter', 'label'];

    connect() {
        this.update();
    }

    update() {
        const v = this.inputTarget.value || '';
        let score = 0;
        if (v.length >= 12) score++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v) || v.length >= 16) score++;
        if (v.length > 0 && v.length < 12) score = Math.min(score, 1);
        if (v.length === 0) score = 0;

        const labels = ['', 'Too weak', 'Fair', 'Good', 'Strong'];
        this.meterTarget.dataset.score = String(score);
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = v.length ? labels[score] : 'At least 12 characters, with letters and numbers.';
        }
    }
}
