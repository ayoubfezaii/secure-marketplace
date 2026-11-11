import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/*
 * Confirmation modal for destructive / state-changing forms: <form data-confirm="Delete this product?">.
 * Attached once to <body>; one shared, accessible Bootstrap modal (focus trap, Esc, aria) replaces window.confirm.
 *
 * SECURITY (rules 3, 13): the form itself is untouched: it is still a POST with its CSRF token, validated by the
 * server. This is only a UX guard against mis-clicks; without JavaScript the form simply submits (the server-side
 * checks are the real protection). No inline onclick= handler is needed, so the CSP keeps script-src 'self'.
 * The message is written with textContent, never innerHTML, so it can't inject markup.
 */
export default class extends Controller {
    static targets = ['modal', 'title', 'message', 'confirmButton'];

    connect() {
        this.pending = null;
        this.onSubmit = this.onSubmit.bind(this);
        document.addEventListener('submit', this.onSubmit, true);
    }

    disconnect() {
        document.removeEventListener('submit', this.onSubmit, true);
    }

    onSubmit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || !this.hasModalTarget) {
            return;
        }
        if (form.dataset.confirmed === '1') {
            delete form.dataset.confirmed;
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();

        this.pending = { form, submitter: event.submitter ?? null };
        this.titleTarget.textContent = form.dataset.confirmTitle || 'Please confirm';
        this.messageTarget.textContent = form.dataset.confirm;
        this.confirmButtonTarget.textContent = form.dataset.confirmButton || 'Confirm';
        this.confirmButtonTarget.className = 'btn ' + (form.dataset.confirmVariant === 'dark' ? 'btn-primary' : 'btn-danger');
        Modal.getOrCreateInstance(this.modalTarget).show();
    }

    accept() {
        if (!this.pending) {
            return;
        }
        const { form, submitter } = this.pending;
        this.pending = null;
        Modal.getOrCreateInstance(this.modalTarget).hide();
        form.dataset.confirmed = '1';
        if (submitter && form.contains(submitter)) {
            form.requestSubmit(submitter);
        } else {
            form.requestSubmit();
        }
    }
}
