// FILE: assets/controllers/autosubmit_controller.js
// WHAT: Stimulus controller "autosubmit".
// ROLE IN THE PROJECT: lets filter forms (sort dropdown, checkboxes) reload the page as soon as a value changes.
// USED BY: list pages with filters (catalogue, dashboards). HTML: <form data-controller="autosubmit"> and
//   data-action="change->autosubmit#submit" on the field.

import { Controller } from '@hotwired/stimulus';

/* Submits a GET filter form when a select / checkbox changes (replaces inline onchange= handlers). */
export default class extends Controller {
    // submit(): sends the form this controller is attached to. requestSubmit() behaves like a click on the submit
    // button (validation and "submit" events still run), unlike form.submit().
    submit() {
        this.element.requestSubmit();
    }
}
