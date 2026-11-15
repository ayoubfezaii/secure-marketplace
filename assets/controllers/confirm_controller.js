// FILE: assets/controllers/confirm_controller.js
// WHAT: Stimulus controller "confirm": shows an "Are you sure?" window before a form is sent.
// ROLE IN THE PROJECT: protects against clicking "Delete" or "Cancel order" by mistake.
// USED BY: every form that has a data-confirm="..." attribute (buyer, seller, admin). The modal HTML is in
//   templates/layouts/_confirm_modal.html.twig.

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
    // "targets" = elements of the modal this controller needs (marked with data-confirm-target="...").
    static targets = ['modal', 'title', 'message', 'confirmButton'];

    // connect(): listens to every form submission of the page (capture phase = before other listeners).
    connect() {
        // The form that is waiting for an answer (none at the start).
        this.pending = null;
        this.onSubmit = this.onSubmit.bind(this);
        document.addEventListener('submit', this.onSubmit, true);
    }

    // disconnect(): stops listening when the controller is removed.
    disconnect() {
        document.removeEventListener('submit', this.onSubmit, true);
    }

    // onSubmit(event): runs each time any form is submitted.
    onSubmit(event) {
        const form = event.target;
        // Ignore forms without data-confirm (and do nothing if the modal is missing).
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || !this.hasModalTarget) {
            return;
        }
        // Second pass: the user already confirmed, let the form go this time.
        if (form.dataset.confirmed === '1') {
            delete form.dataset.confirmed;
            return;
        }
        // First pass: stop the submission and show the modal instead.
        event.preventDefault();
        event.stopImmediatePropagation();

        // Remember the form and the button that was clicked, then fill the modal texts.
        this.pending = { form, submitter: event.submitter ?? null };
        this.titleTarget.textContent = form.dataset.confirmTitle || 'Please confirm';
        this.messageTarget.textContent = form.dataset.confirm;
        this.confirmButtonTarget.textContent = form.dataset.confirmButton || 'Confirm';
        this.confirmButtonTarget.className = 'btn ' + (form.dataset.confirmVariant === 'dark' ? 'btn-primary' : 'btn-danger');
        Modal.getOrCreateInstance(this.modalTarget).show();
    }

    // accept(): called when the user clicks the confirm button of the modal.
    accept() {
        if (!this.pending) {
            return;
        }
        // Close the modal, mark the form as confirmed and submit it again (with the same button).
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
