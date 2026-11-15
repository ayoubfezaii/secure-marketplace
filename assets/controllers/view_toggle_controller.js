// FILE: assets/controllers/view_toggle_controller.js
// WHAT: Stimulus controller "view-toggle": switches the catalogue between a grid and a list.
// ROLE IN THE PROJECT: display preference of the catalogue page.
// USED BY: templates/catalog/index.html.twig (everyone).

import { Controller } from '@hotwired/stimulus';

/* Grid / list toggle for the catalogue. The choice is a per-browser preference only (localStorage, optional). */
export default class extends Controller {
    // "targets": the product container and the two buttons (grid / list).
    static targets = ['container', 'button'];

    // connect(): applies the mode saved in the browser, "grid" by default.
    connect() {
        let mode = 'grid';
        try { mode = window.localStorage.getItem('catalog-view') || 'grid'; } catch (e) { /* storage blocked */ }
        this.apply(mode);
    }

    // switch(event): called by a click on a button; saves the chosen mode and applies it.
    switch(event) {
        const mode = event.currentTarget.dataset.mode;
        try { window.localStorage.setItem('catalog-view', mode); } catch (e) { /* storage blocked */ }
        this.apply(mode);
    }

    // apply(mode): changes the CSS classes of the container and columns, and highlights the active button.
    apply(mode) {
        this.containerTarget.classList.toggle('product-list', mode === 'list');
        this.containerTarget.querySelectorAll('[data-grid-col]').forEach((col) => {
            col.className = mode === 'list' ? 'col-12' : col.dataset.gridCol;
        });
        this.buttonTargets.forEach((b) => {
            const active = b.dataset.mode === mode;
            b.classList.toggle('active', active);
            b.setAttribute('aria-pressed', String(active));
        });
    }
}
