import { Controller } from '@hotwired/stimulus';

/* Submits a GET filter form when a select / checkbox changes (replaces inline onchange= handlers). */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
