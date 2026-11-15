// FILE: assets/money.js
// WHAT: one function that formats a number as a price in dinars ("1 250,500 DT").
// ROLE IN THE PROJECT: JavaScript twin of App\Service\Money::format(), so charts and the live product preview
//   show prices exactly like the server does.
// USED BY: chart_controller.js (seller / admin dashboards) and preview_controller.js (seller product form).

/*
 * Tunisian dinar formatting for the browser (charts, live product preview). Mirrors App\Service\Money::format():
 * 3 decimals, space as thousands separator, comma as decimal separator, then " DT". 1250.5 -> "1 250,500 DT".
 *
 * Display only: amounts shown here were computed by the server; nothing formatted here is ever sent back as a price.
 * Written by hand (no toLocaleString) so the result is the same in every browser and locale.
 */
// formatMoney(value): takes a number (or a numeric string) and returns the formatted text. Returns '' for a non-number.
export function formatMoney(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return '';
    }
    // Split the absolute value into the integer part and exactly 3 decimals.
    const [integer, decimals] = Math.abs(n).toFixed(3).split('.');
    // Put a space between every group of 3 digits of the integer part (1250 -> "1 250").
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

    return `${n < 0 ? '-' : ''}${grouped},${decimals} DT`;
}
