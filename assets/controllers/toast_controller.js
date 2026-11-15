// FILE: assets/controllers/toast_controller.js
// WHAT: Stimulus controller "toast": displays the flash messages ("Product updated.") as small pop-ups.
// ROLE IN THE PROJECT: feedback after each action.
// USED BY: templates/layouts/_toasts.html.twig, included in every layout (everyone).

import { Controller } from '@hotwired/stimulus';
import { Toast } from 'bootstrap';

/*
 * Flash messages rendered as toasts. The markup is rendered (and auto-escaped) by Twig; without JavaScript the
 * toasts stay visible as plain boxes. This controller only adds auto-hide and the close animation.
 */
export default class extends Controller {
    // connect(): shows every toast of the page. Normal messages hide after 6 seconds; errors stay until closed.
    connect() {
        this.element.querySelectorAll('.toast').forEach((el) => {
            const isError = el.classList.contains('toast-error') || el.classList.contains('toast-danger');
            Toast.getOrCreateInstance(el, { autohide: !isError, delay: 6000 }).show();
        });
    }
}
