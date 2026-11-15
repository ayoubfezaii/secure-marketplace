// FILE: assets/controllers/sidebar_controller.js
// WHAT: Stimulus controller "sidebar": folds / unfolds the left menu of the dashboards.
// ROLE IN THE PROJECT: comfort of the dashboard layout.
// USED BY: templates/layouts/dashboard.html.twig (buyer, seller, admin).

import { Controller } from '@hotwired/stimulus';

/* Collapsible dashboard sidebar (desktop). Remembered per browser; purely cosmetic. */
export default class extends Controller {
    // connect(): restores the last choice saved in the browser (localStorage). try/catch: storage can be blocked.
    connect() {
        try {
            if (window.localStorage.getItem('sidebar-collapsed') === '1') {
                this.element.classList.add('collapsed');
            }
        } catch (e) { /* storage blocked */ }
    }

    // toggle(): called by the fold button. Adds or removes the CSS class "collapsed" and saves the choice.
    toggle() {
        const collapsed = this.element.classList.toggle('collapsed');
        try { window.localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0'); } catch (e) { /* storage blocked */ }
    }
}
