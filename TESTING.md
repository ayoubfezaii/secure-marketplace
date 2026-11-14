# TESTING.md — End-to-end checklist (Maison Secure)

Tick each box while testing. Security attack tests (Burp, curl) are in `SECURITY.md`; this file checks that every
feature works for every role, and that each role is refused outside its own area.

## 0. Preparation

- [ ] XAMPP MySQL/MariaDB running, Mailpit running (UI http://localhost:8025)
- [ ] `composer install` (also downloads the importmap vendor files into `assets/vendor/`)
- [ ] `.env.local` contains `DATABASE_URL`, `MAILER_DSN=smtp://localhost:1025`, `APP_SECRET`, `APP_ENCRYPTION_KEY` (see `SECURITY.md` §3)
- [ ] `php bin/console doctrine:migrations:migrate -n`
- [ ] `php bin/console doctrine:fixtures:load -n` → prints the demo accounts (about 10 s, generates ~100 product images)
- [ ] `symfony serve -d` → open https://127.0.0.1:8000
- [ ] Use a normal window + a private window to be logged in as two users at once.

### Demo accounts (password for all: `Password2026!`)

| Role | Email | Notes |
|---|---|---|
| Admin | `admin@shop.test` | Only admin (admins are created with `php bin/console app:create-admin <email>`) |
| Seller | `seller1@shop.test` | Atelier Lumière (clothing, bags), orders to ship, reviews to answer |
| Seller | `seller2@shop.test` | Maison Argile (ceramics, home) |
| Seller | `seller3@shop.test` | Papeterie Nord (books, beauty), one hidden product |
| Seller | `seller4@shop.test` | Sonic Craft (tech, jewellery), **2FA ON**, TOTP secret `JBSWY3DPEHPK3PXP` (add it to any authenticator app) |
| Buyer | `buyer1@shop.test` | Cart with 2 items, 2 orders still "paid" (cancel demo), 1 cash-on-delivery order "placed", wishlist, 2 addresses |
| Buyer | `buyer2@shop.test` – `buyer7@shop.test` | Order histories; buyer3, buyer6, buyer7 have a **pending** seller application |
| Buyer | `buyer5@shop.test` | **Rejected** seller application (reason visible) |
| Buyer | `buyer8@shop.test` | **Blocked** by the admin: login refused |

### Automated tests

- [ ] `php bin/phpunit` → `OK (126 tests)`. The bootstrap creates `secure_marketplace_test`, migrates it and loads the
      fixtures (needs `.env.test.local` with the `DATABASE_URL`, git-ignored). Test uploads go to `var/uploads/test/`.

---

## 1. Visitor (not logged in)

- [ ] Home: announcement bar, hero, category circles + tiles, best sellers, promo banner with a **live countdown**, stats, reviews carousel (arrows scroll), newsletter, FAQ accordion
- [ ] Header: Categories dropdown, Deals, About, search; on a phone width (DevTools device mode) the ☰ button opens the offcanvas menu
- [ ] Footer: 4 columns, newsletter, generic payment icons
- [ ] Newsletter: subscribe → "Thank you!" toast; same address again → same message (no enumeration); 6th try within an hour → "Too many requests"
- [ ] **Shop** `/products`: filters category / price / rating / in stock / on sale, sort select (auto-submits), grid/list toggle (remembered), result count, removable filter chips, pagination, empty state with a nonsense search
- [ ] Product page: gallery thumbnails switch the image, NEW / -xx% badges, old price struck through, stock status, rating summary with distribution bars, tabs, seller box → **Visit shop**, "You may also like"
- [ ] Shop page `/shop/atelier-lumiere`: banner, rating, products, sort
- [ ] `/cart`, `/checkout`, `/buyer`, `/seller`, `/admin`, `/account/profile` → redirected to the login page
- [ ] Login with a wrong password → "Invalid credentials."; with an unknown email → same message; eye icon shows/hides the password
- [ ] Unknown URL → custom 404 page (in dev, preview the prod pages at `/_error/404`, `/_error/403`, `/_error/500`)

## 2. Registration, password reset, 2FA

- [ ] Register: name, email, password (strength meter fills while typing), repeat, terms → redirected to login; the new account is a **buyer**
- [ ] Weak password (`password1234`) → refused (breach list / policy); mismatched passwords → error
- [ ] Forgot password with an existing and an unknown email → same "Check your email" page; the link in Mailpit works once only
- [ ] Log in as `seller4@shop.test` → 2FA page; wrong code → error; correct code from the app → seller dashboard

### Currency (Tunisian dinar)
- [ ] Every price on the site reads like `189,000 DT` / `1 800,000 DT` (3 decimals, comma, space for thousands): product cards, product page, cart, checkout summary, order pages, dashboards, stat cards
- [ ] No "€" or "EUR" anywhere: `grep -rnI "€\|EUR" templates src assets/controllers` → nothing
- [ ] Shop filter **Price (DT)**: min 100, max 200 → chip `100,000 DT – 200,000 DT`

---

## 3. Buyer (`buyer1@shop.test`)

### Shopping
- [ ] Heart on a product card → toast + filled heart; header wishlist counter updates
- [ ] Add to cart with the +/− stepper; header cart counter updates
- [ ] Cart: +/− updates the quantity automatically, Remove, order summary, empty state after removing everything
- [ ] Checkout step 1 **Address**: pick a saved address or add a new one (max 5) → step 2
- [ ] Step 2 **Payment**: `4242 4242 4242 4242`, `12/30`, `123`; a wrong number (`4242 4242 4242 4241`) → "not valid"; expired date → error
- [ ] Step 3 **Review**: address + "Card ending in 4242" → **Place order** → confirmation page with an order number `MS-…`
- [ ] Browser Back + resubmit the "Place order" form → no second order (payment token is single use)

### Cash on delivery
- [ ] Step 2 shows two radio cards, **Pay by card** and **Cash on delivery** ("Pay in cash when your order arrives"); choosing cash hides the card form, choosing card shows it again (no page reload)
- [ ] Cash, single-shop cart under 500 DT → Review shows "Cash on delivery" → **Place order** → confirmation "To pay in cash at delivery"; no card was asked
- [ ] The order page and **My orders** show *Cash on delivery* + *To pay on delivery*; status **Placed**; timeline Placed → Shipped → Delivered → Paid in cash
- [ ] A **placed** cash order has **Cancel order** → Cancelled, payment "Not collected", stock restored; once shipped the button is gone
- [ ] Cart above 500 DT → cash → "Cash on delivery is limited to orders up to 500,000 DT"; paying by card still works
- [ ] Cart with items from two shops → cash → "only available when all the items come from the same shop"
- [ ] With 2 cash orders waiting to be paid, a third → "You already have 2 cash-on-delivery orders waiting to be paid"
- [ ] Bypass attempt: pass step 2 with a small cart, add items in another tab until the total is above 500 DT, then **Place order** → back to the payment step with the limit message, no order created
- [ ] Burp: `checkout_form[paymentMethod]=free` → 422 "The selected choice is invalid."; method `card` with empty card fields → 422 card errors
- [ ] Place / cancel cash orders 6 times within an hour → "You placed too many cash-on-delivery orders in a short time"

### Dashboard (`/buyer`)
- [ ] Overview: 4 stat cards, recent orders, products to review, recommendations
- [ ] **My orders**: status chips filter the list; order detail shows the timeline, items, totals, address, masked card
- [ ] A **paid** order has **Cancel order** → confirm modal → status Cancelled, stock restored; shipped/delivered orders have no cancel button
- [ ] **Wishlist**: Move to cart, remove
- [ ] **Addresses**: add, edit, set default, delete (confirm modal); a 6th address is refused
- [ ] **My reviews**: edit (rating + text, "edited" mark), delete; on the product page "Write a review" appears only for products you bought and haven't reviewed
- [ ] **Become a seller**: submit an application → status *Pending*; the form disappears (one pending application max)
- [ ] **Profile**: change display name / phone → avatar initials update
- [ ] **Security**: change password (wrong current password → error; success → logged out); enable 2FA (scan QR, confirm code); login history lists recent sign-ins with IPs
- [ ] `/seller`, `/admin`, another buyer's order URL → 403

## 4. Seller (`seller1@shop.test`)

- [ ] Overview: revenue this month with % vs last month, units, pending shipments, active products, rating, **30-day chart renders**, top 5, low stock, orders to ship, latest reviews; notification bell lists items to ship / low stock / reviews to answer
- [ ] Sidebar collapse button (desktop) is remembered; ☰ opens the sidebar on mobile
- [ ] **Products**: search, status / stock filters, sortable columns, sales count, click the status badge to hide/show, delete with confirm modal
- [ ] New / edit product: **Price (DT)** accepts `35.500`; `0`, `-5`, `300000.001` and `35.5001` are refused (also when sent with Burp, bypassing the field's min/max/step); **live preview** shows the price as `35,500 DT` and updates while typing (name, price, sale badge); upload up to 4 images, import one from an `https://` URL, tick "Remove" on an image; a 5th image → error
- [ ] **Orders**: status chips; detail shows only my items + shipping name/address (no buyer email/phone/card); **Mark as shipped → Mark as delivered**
- [ ] **Cash on delivery** (orders marked "Cash"): detail shows a *Cash on delivery* panel with the amount to collect; flow **Placed → Shipped → Delivered**, then **Cash collected** (confirm modal) → payment *Collected*; revenue / analytics / payouts count the order only from that moment
- [ ] On a shipped (or delivered, not paid) cash order: **Refused at delivery** → order Cancelled, stock restored; both buttons disappear
- [ ] As `seller2`, `POST /seller/orders/<a seller1 cash order uuid>/cash-collected` (Burp) → 403; `GET` on the same URL → 405
- [ ] **Reviews**: rating / "awaiting reply" filters; publish a reply → visible on the product page; the reply form disappears (one reply per review)
- [ ] **Analytics**: 7d / 30d / 90d buttons, revenue bar chart, category doughnut, best-sellers table with share bars; chart axis and tooltips are in DT (`1 250,500 DT`)
- [ ] **My shop**: change name / address (slug) / description / logo; a taken slug → error; "Open public page"
- [ ] **Payouts**: save an IBAN (`FR76 3000 6000 0112 3456 7890 189`) → shown as `•••• 0189`; payout history by month
- [ ] `/cart`, `/checkout`, `/buyer`, `/admin`, another seller's product edit URL → 403

## 5. Admin (`admin@shop.test`)

- [ ] Overview: 9 stat cards (incl. **Cash to collect** = total of pending cash orders, not in Revenue; click → filtered order list), orders & revenue line chart (revenue axis and tooltip in DT), users-by-role doughnut, orders-per-day bars, latest security and audit events
- [ ] **Users**: search, role / status / 2FA filters, sortable columns; detail page shows orders, products, login history, masked IBAN for sellers (never the full IBAN, password or 2FA secret)
- [ ] On `buyer3`: Lock (a session of buyer3 in another window is ended on the next click), Unlock, Switch to seller (shop created) and back (products hidden), Force password reset (old password stops working, link in Mailpit), Reset 2FA (only shown when enabled)
- [ ] On your own account: no action buttons ("cannot change your own account")
- [ ] **Seller applications**: pending first; approve one (applicant becomes a seller with a shop); reject another without a reason → refused, with a reason → status Rejected, reason visible to the applicant
- [ ] **Products**: filters by seller / category / status, deactivate / reactivate, delete
- [ ] **Categories**: create (slug, icon list, position), edit; delete is disabled for categories with products
- [ ] **Orders**: filters status / **payment method** / **payment status** / seller / dates / buyer; detail → cancel (paid, or placed for cash) or refund (paid/shipped/delivered with the money received; an uncollected cash order has no Refund button) → stock restored, audit entry
- [ ] **Audit log**: actions `order.cash_placed`, `order.cash_collected`, `order.cash_refused`, `order.cash_blocked` (with the reason); blocked cash orders also appear in the latest security events
- [ ] **Reviews**: rating filter, search, delete a review, delete a seller reply
- [ ] **Audit log**: filters action / user / IP / dates, pagination, **Export CSV** (cells starting with `= + - @` are prefixed with `'`; order rows have an `amount` with 3 decimals and `TND` in the currency column, header says "Currency: TND")
- [ ] **Security center**: failed logins 24h / 7d chart, top IPs (documentation IPs `203.0.113.x`), locked accounts with Unlock, blocked SSRF and uploads, users without 2FA, headers checklist
- [ ] `/buyer`, `/cart`, `/seller` → 403

## 6. Hardening checks

- [ ] `curl -skI https://127.0.0.1:8000/` shows CSP, HSTS, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, no X-Powered-By
- [ ] DevTools console on any page: **no CSP violation**; charts, dropdowns, modals, toasts and the countdown all work
- [ ] `grep -rnE 'style="|onclick=|<script' templates` → no inline style / handler / script in pages
- [ ] Session cookie flags: Secure, HttpOnly, SameSite=Lax
- [ ] Every page at 390 px width (DevTools device mode): no horizontal scrollbar, tables turn into cards
- [ ] `composer audit`, `php bin/console importmap:audit`, `vendor/bin/phpstan analyse` → all clean
- [ ] `php bin/console lint:twig templates`, `lint:yaml config`, `lint:container` → OK
