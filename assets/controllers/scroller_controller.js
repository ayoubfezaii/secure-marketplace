// FILE: assets/controllers/scroller_controller.js
// WHAT: Stimulus controller "scroller": previous / next arrows of a horizontal list.
// ROLE IN THE PROJECT: carousels of the home page (products, reviews).
// USED BY: templates/home/index.html.twig (everyone).

import { Controller } from '@hotwired/stimulus';

/* Horizontal scroll-snap carousel with previous / next buttons (keyboard and touch scrolling work natively). */
export default class extends Controller {
    // "targets": the scrolling row ("track").
    static targets = ['track'];

    // prev() / next(): called by the arrow buttons.
    prev() { this.scrollStep(-1); }
    next() { this.scrollStep(1); }

    // scrollStep(dir): scrolls by the width of one item (+ the gap), to the left (-1) or to the right (1).
    scrollStep(dir) {
        const track = this.trackTarget;
        const item = track.firstElementChild;
        const step = item ? item.getBoundingClientRect().width + 20 : track.clientWidth;
        track.scrollBy({ left: dir * step, behavior: 'smooth' });
    }
}
