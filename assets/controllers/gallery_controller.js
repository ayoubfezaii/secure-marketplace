// FILE: assets/controllers/gallery_controller.js
// WHAT: Stimulus controller "gallery": clicking a small picture shows it as the big picture.
// ROLE IN THE PROJECT: image gallery of the product page.
// USED BY: templates/catalog/show.html.twig (everyone).

import { Controller } from '@hotwired/stimulus';

/* Product gallery: thumbnails swap the main image. URLs come from our own media route (data attributes). */
export default class extends Controller {
    // "targets": the big image ("main") and the small buttons ("thumb").
    static targets = ['main', 'thumb'];

    // select(event): called on a click on a thumbnail.
    select(event) {
        const button = event.currentTarget;
        // Copy the image address and its description from the clicked button to the big image.
        this.mainTarget.src = button.dataset.src;
        this.mainTarget.alt = button.dataset.alt || '';
        // Mark the clicked thumbnail as the current one (for the style and for screen readers).
        this.thumbTargets.forEach((t) => t.setAttribute('aria-current', String(t === button)));
    }
}
