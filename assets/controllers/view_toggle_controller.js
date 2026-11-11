import { Controller } from '@hotwired/stimulus';

/* Grid / list toggle for the catalogue. The choice is a per-browser preference only (localStorage, optional). */
export default class extends Controller {
    static targets = ['container', 'button'];

    connect() {
        let mode = 'grid';
        try { mode = window.localStorage.getItem('catalog-view') || 'grid'; } catch (e) { /* storage blocked */ }
        this.apply(mode);
    }

    switch(event) {
        const mode = event.currentTarget.dataset.mode;
        try { window.localStorage.setItem('catalog-view', mode); } catch (e) { /* storage blocked */ }
        this.apply(mode);
    }

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
