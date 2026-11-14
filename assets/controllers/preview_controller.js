import { Controller } from '@hotwired/stimulus';
import { formatMoney } from '../money.js';

/*
 * Live preview card in the seller product form. Values are copied with textContent (never innerHTML), so what a
 * seller types is displayed as text, exactly like the auto-escaped storefront will show it (rule 2).
 */
export default class extends Controller {
    static targets = ['name', 'price', 'oldPrice', 'category', 'outName', 'outPrice', 'outOldPrice', 'outCategory', 'outSale'];

    connect() {
        this.update();
    }

    update() {
        const num = (v) => parseFloat(String(v || '').replace(',', '.'));
        const fmt = (v) => {
            const n = num(v);
            return Number.isFinite(n) ? formatMoney(n) : '';
        };
        if (this.hasNameTarget) this.outNameTarget.textContent = this.nameTarget.value || 'Product name';
        if (this.hasPriceTarget) this.outPriceTarget.textContent = fmt(this.priceTarget.value) || formatMoney(0);
        if (this.hasCategoryTarget) {
            const opt = this.categoryTarget.selectedOptions[0];
            this.outCategoryTarget.textContent = opt ? opt.textContent : '';
        }
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
