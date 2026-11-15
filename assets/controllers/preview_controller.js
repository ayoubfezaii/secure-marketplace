// FILE: assets/controllers/preview_controller.js
// WHAT: Stimulus controller "preview": updates a sample product card while the seller types.
// ROLE IN THE PROJECT: live preview in the product form.
// USED BY: templates/seller/products/form.html.twig (seller).

import { Controller } from '@hotwired/stimulus';
import { formatMoney } from '../money.js';

/*
 * Live preview card in the seller product form. Values are copied with textContent (never innerHTML), so what a
 * seller types is displayed as text, exactly like the auto-escaped storefront will show it (rule 2).
 */
export default class extends Controller {
    // "targets": the form fields (name, price, oldPrice, category) and the places of the preview card (out...).
    static targets = ['name', 'price', 'oldPrice', 'category', 'outName', 'outPrice', 'outOldPrice', 'outCategory', 'outSale'];

    // connect(): fills the preview when the page opens.
    connect() {
        this.update();
    }

    // update(): called on each change of a field.
    update() {
        // num(): text to number (accepts a comma). fmt(): number to "35,500 DT" (empty text if not a number).
        const num = (v) => parseFloat(String(v || '').replace(',', '.'));
        const fmt = (v) => {
            const n = num(v);
            return Number.isFinite(n) ? formatMoney(n) : '';
        };
        // SECURITY: XSS - textContent writes TEXT. If the seller types <script>, it is displayed, never executed.
        if (this.hasNameTarget) this.outNameTarget.textContent = this.nameTarget.value || 'Product name';
        if (this.hasPriceTarget) this.outPriceTarget.textContent = fmt(this.priceTarget.value) || formatMoney(0);
        if (this.hasCategoryTarget) {
            const opt = this.categoryTarget.selectedOptions[0];
            this.outCategoryTarget.textContent = opt ? opt.textContent : '';
        }
        // Sale badge: shown only when the old price is higher than the price; shows the discount in percent.
        if (this.hasOldPriceTarget && this.hasPriceTarget) {
            const price = num(this.priceTarget.value);
            const old = num(this.oldPriceTarget.value);
            const onSale = Number.isFinite(price) && Number.isFinite(old) && old > price;
            this.outOldPriceTarget.textContent = onSale ? fmt(old) : '';
            this.outSaleTarget.hidden = !onSale;
            this.outSaleTarget.textContent = onSale ? '-' + Math.round((1 - price / old) * 100) + '%' : '';
        }
    }
}
