import { Controller } from '@hotwired/stimulus';

/* Product gallery: thumbnails swap the main image. URLs come from our own media route (data attributes). */
export default class extends Controller {
    static targets = ['main', 'thumb'];

    select(event) {
        const button = event.currentTarget;
        this.mainTarget.src = button.dataset.src;
        this.mainTarget.alt = button.dataset.alt || '';
        this.thumbTargets.forEach((t) => t.setAttribute('aria-current', String(t === button)));
    }
}
