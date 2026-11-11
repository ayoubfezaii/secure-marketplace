import { Controller } from '@hotwired/stimulus';

/* Collapsible dashboard sidebar (desktop). Remembered per browser; purely cosmetic. */
export default class extends Controller {
    connect() {
        try {
            if (window.localStorage.getItem('sidebar-collapsed') === '1') {
                this.element.classList.add('collapsed');
            }
        } catch (e) { /* storage blocked */ }
    }

    toggle() {
        const collapsed = this.element.classList.toggle('collapsed');
        try { window.localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0'); } catch (e) { /* storage blocked */ }
    }
}
