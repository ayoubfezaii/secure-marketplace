/*
 * Tunisian dinar formatting for the browser (charts, live product preview). Mirrors App\Service\Money::format():
 * 3 decimals, space as thousands separator, comma as decimal separator, then " DT". 1250.5 -> "1 250,500 DT".
 *
 * Display only: amounts shown here were computed by the server; nothing formatted here is ever sent back as a price.
 * Written by hand (no toLocaleString) so the result is the same in every browser and locale.
 */
export function formatMoney(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return '';
    }
    const [integer, decimals] = Math.abs(n).toFixed(3).split('.');
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

    return `${n < 0 ? '-' : ''}${grouped},${decimals} DT`;
}
