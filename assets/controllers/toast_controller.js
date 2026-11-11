import { Controller } from '@hotwired/stimulus';
import { Toast } from 'bootstrap';

/*
 * Flash messages rendered as toasts. The markup is rendered (and auto-escaped) by Twig; without JavaScript the
 * toasts stay visible as plain boxes. This controller only adds auto-hide and the close animation.
 */
export default class extends Controller {
    connect() {
        this.element.querySelectorAll('.toast').forEach((el) => {
            const isError = el.classList.contains('toast-error') || el.classList.contains('toast-danger');
            Toast.getOrCreateInstance(el, { autohide: !isError, delay: 6000 }).show();
        });
    }
}
