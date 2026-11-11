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
| Buyer | `buyer1@shop.test` | Cart with 2 items, 2 orders still "paid" (cancel demo), wishlist, 2 addresses |
| Buyer | `buyer2@shop.test` – `buyer7@shop.test` | Order histories; buyer3, buyer6, buyer7 have a **pending** seller application |
| Buyer | `buyer5@shop.test` | **Rejected** seller application (reason visible) |
| Buyer | `buyer8@shop.test` | **Blocked** by the admin: login refused |

### Automated tests

- [ ] `php bin/phpunit` → `OK (81 tests)`. The bootstrap creates `secure_marketplace_test`, migrates it and loads the
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

## 3. Buyer (`buyer1@shop.test`)

### Shopping
- [ ] Heart on a product card → toast + filled heart; header wishlist counter updates
- [ ] Add to cart with the +/− stepper; header cart counter updates
- [ ] Cart: +/− updates the quantity automatically, Remove, order summary, empty state after removing everything
- [ ] Checkout step 1 **Address**: pick a saved address or add a new one (max 5) → step 2
- [ ] Step 2 **Payment**: `4242 4242 4242 4242`, `12/30`, `123`; a wrong number (`4242 4242 4242 4241`) → "not valid"; expired date → error
- [ ] Step 3 **Review**: address + "Card ending in 4242" → **Place order** → confirmation page with an order number `MS-…`
- [ ] Browser Back + resubmit the "Place order" form → no second order (payment token is single use)

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
- [ ] New / edit product: **live preview** updates while typing (name, price, sale badge); upload up to 4 images, import one from an `https://` URL, tick "Remove" on an image; a 5th image → error
- [ ] **Orders**: status chips; detail shows only my items + shipping name/address (no buyer email/phone/card); **Mark as shipped → Mark as delivered**
- [ ] **Reviews**: rating / "awaiting reply" filters; publish a reply → visible on the product page; the reply form disappears (one reply per review)
- [ ] **Analytics**: 7d / 30d / 90d buttons, revenue bar chart, category doughnut, best-sellers table with share bars
- [ ] **My shop**: change name / address (slug) / description / logo; a taken slug → error; "Open public page"
- [ ] **Payouts**: save an IBAN (`FR76 3000 6000 0112 3456 7890 189`) → shown as `•••• 0189`; payout history by month
- [ ] `/cart`, `/checkout`, `/buyer`, `/admin`, another seller's product edit URL → 403

## 5. Admin (`admin@shop.test`)

- [ ] Overview: 8 stat cards, orders & revenue line chart, users-by-role doughnut, orders-per-day bars, latest security and audit events
- [ ] **Users**: search, role / status / 2FA filters, sortable columns; detail page shows orders, products, login history, masked IBAN for sellers (never the full IBAN, password or 2FA secret)
- [ ] On `buyer3`: Lock (a session of buyer3 in another window is ended on the next click), Unlock, Switch to seller (shop created) and back (products hidden), Force password reset (old password stops working, link in Mailpit), Reset 2FA (only shown when enabled)
- [ ] On your own account: no action buttons ("cannot change your own account")
- [ ] **Seller applications**: pending first; approve one (applicant becomes a seller with a shop); reject another without a reason → refused, with a reason → status Rejected, reason visible to the applicant
- [ ] **Products**: filters by seller / category / status, deactivate / reactivate, delete
- [ ] **Categories**: create (slug, icon list, position), edit; delete is disabled for categories with products
- [ ] **Orders**: filters status / seller / dates / buyer; detail → cancel (paid) or refund (paid/shipped/delivered) → stock restored, audit entry
- [ ] **Reviews**: rating filter, search, delete a review, delete a seller reply
- [ ] **Audit log**: filters action / user / IP / dates, pagination, **Export CSV** (cells starting with `= + - @` are prefixed with `'`)
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
