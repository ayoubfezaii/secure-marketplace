import { Controller } from '@hotwired/stimulus';

/* Horizontal scroll-snap carousel with previous / next buttons (keyboard and touch scrolling work natively). */
export default class extends Controller {
    static targets = ['track'];

    prev() { this.scrollStep(-1); }
    next() { this.scrollStep(1); }

    scrollStep(dir) {
        const track = this.trackTarget;
        const item = track.firstElementChild;
        const step = item ? item.getBoundingClientRect().width + 20 : track.clientWidth;
        track.scrollBy({ left: dir * step, behavior: 'smooth' });
    }
}
