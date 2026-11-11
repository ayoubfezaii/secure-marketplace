# REDESIGN + DASHBOARDS PLAN — Secure Marketplace

Read CLAUDE.md first. ALL its security rules still apply to every new page, form and feature.
The platform from BUILD_PLAN.md already exists: reuse and improve it, never rebuild from zero, never remove a security protection.

Work through every phase WITHOUT stopping to ask questions. Make good decisions yourself.
After each phase:
- `php bin/console doctrine:migrations:migrate -n` if entities changed
- `php bin/console lint:twig templates` + `php bin/console lint:container` + `php bin/console cache:clear` and fix errors
- `git add . && git commit -m "Redesign phase X: <name>"`
- print "REDESIGN PHASE X DONE"

---

## Phase R1 — Design system

Look at every image in the `design-refs/` folder. They are INSPIRATION ONLY:
- Do NOT copy their brand names (ShopCart, Lumora, Lunora), logos, real brand logos (Nike, Samsung, Apple...) or photos of people.
- Create an original brand: **"Maison Secure"** (tagline: "Shop beautifully. Shop safely."), with a simple SVG logo (shield + shopping bag shape).

Visual direction (mix of the references): elegant, minimal, premium fashion-store feel.
- Palette (CSS custom properties in one file):
  - `--ink: #141414` (text, primary buttons), `--cream: #F7F3EE` (page background), `--sand: #EDE6DC` (sections, cards), `--taupe: #8B7E74` (muted text), `--white: #FFFFFF`
  - accent `--accent: #E8701A` (sale badges, promo, highlights only), success `#2E7D5B`, danger `#C0392B`, warning `#D4A017`
- Typography: headings in a serif (Playfair Display or DM Serif Display), body in Inter or DM Sans. Download the .woff2 files into `assets/fonts/` and load them with @font-face (no Google Fonts CDN, it would break the CSP).
- Components: rounded corners 12–16px, soft shadows, black pill/rounded buttons, outline secondary buttons, cards with hover lift, badges (NEW, -30%, status), star ratings, skeleton/empty states with illustration + call to action, toasts for flash messages, breadcrumbs, pagination, tabs, accordions, modal confirm for destructive actions (CSRF-protected forms inside).
- Icons: Bootstrap Icons, installed locally via importmap/AssetMapper.
- Keep Bootstrap 5 as the base but customise it (symfonycasts/sass-bundle with variables, or a custom CSS layer overriding Bootstrap). Everything must be fully responsive (mobile first) and accessible (contrast AA, labels, focus states, alt texts, keyboard navigation).
- CSP must stay strict: no inline `<script>`, no inline `style=""`, no `onclick=`. Use Stimulus controllers for all JS.
- Create reusable Twig components / macros: product_card, rating_stars, stat_card, status_badge, empty_state, page_header, confirm_delete_form.

## Phase R2 — Public storefront

- **Header**: top announcement bar (free shipping / 30-day returns / secure checkout), logo, nav (Home, Shop, Categories dropdown, Deals, About), search bar, icons (account, wishlist with count, cart with count). Mobile: offcanvas menu.
- **Home**: hero (big serif title "Discover pieces made to last", text, 2 buttons, trust row: free shipping, easy returns, secure payment) with an elegant CSS/SVG composition (no stock photos of people). Shop by category (round icons row like reference 3), category cards grid, "Best sellers" product grid, promo banner with countdown (Stimulus), "Why shop with us" stats (customers, products, positive reviews, support), latest reviews carousel, newsletter block (CSRF + rate limited, store emails in NewsletterSubscriber entity), FAQ accordion.
- **Footer**: dark, 4 columns (shop, customer service, account, contact), newsletter input, social icons, payment icons as generic SVGs, copyright.
- **Catalogue /products**: left sidebar filters (category, price range, rating, in stock), sort select, grid/list toggle, result count, pagination, empty state.
- **Product page**: image gallery with thumbnails (up to 4 images per product), title, rating, price with old price + discount badge, stock status, quantity selector, Add to cart, Add to wishlist, trust row, tabs (Description, Shipping & returns, Reviews). Reviews section like reference 2: average score, rating distribution bars, review cards, "Write a review" button (only if allowed). Seller box with shop name and link to seller shop page. "You may also like" products.
- **Seller shop page /shop/{slug}**: banner, logo, description, rating, products.
- **Cart**: line items with image, quantity +/- , remove, order summary card, empty state.
- **Checkout**: 3 steps (Address → Payment → Review), progress indicator, saved addresses selector, simulated card form, order summary. Confirmation page with order number.
- Placeholder product images: improve the fixtures generator (GD) to create attractive images: soft gradient background in the palette + a large category icon shape + product initials. Hero/category visuals in SVG.

## Phase R3 — Auth pages (reference 5)

- Split-screen layout: left panel = dark/warm gradient art panel with logo and a short brand quote (SVG/CSS art, no photo); right panel = form.
- Pages: login, register (buyer by default, link "Want to sell? Apply as a seller after registering"), forgot password, reset password, 2FA code page, email-sent confirmation.
- Show/hide password toggle (Stimulus), password strength meter, clear error messages that do NOT reveal whether an email exists.
- NO Google/Facebook login buttons (not implemented — do not show fake buttons).
- Custom error pages 403 / 404 / 500 in the new design.

## Phase R4 — Dashboard layout (shared)

- App-style layout for /buyer, /seller, /admin: left sidebar (collapsible, icons + labels, active state, role badge), top bar (search, notifications bell, user menu with avatar initials, link back to store), content area with page header + breadcrumbs.
- Each role sees ONLY its own sidebar and routes.
- Charts with Chart.js (via importmap, data passed through data-attributes as JSON, rendered by a Stimulus controller).
- All tables: search, filters, sort (whitelisted), pagination, empty states, responsive (cards on mobile).
- All destructive or state-changing actions: POST + CSRF + confirm modal + flash toast + AuditLog entry.

## Phase R5 — Roles & privileges (implement exactly this)

Roles are exclusive: a user is BUYER, SELLER or ADMIN. Admins are created only by the console command `app:create-admin`.

| Capability | Visitor | Buyer | Seller | Admin |
|---|---|---|---|---|
| Browse, search, view products & shops | ✅ | ✅ | ✅ | ✅ |
| Register / login / reset password / 2FA | ✅ | ✅ | ✅ | ✅ |
| Cart, checkout, place orders | ❌ | ✅ | ❌ | ❌ |
| Wishlist, saved addresses | ❌ | ✅ | ❌ | ❌ |
| View own orders, cancel order while status = paid | ❌ | ✅ | ❌ | ❌ |
| Write / edit / delete own review (only for purchased products, once per product) | ❌ | ✅ | ❌ | ❌ |
| Apply to become a seller | ❌ | ✅ | ❌ | ❌ |
| Manage own shop profile (name, slug, description, logo) | ❌ | ❌ | ✅ | ❌ |
| Create / edit / delete / activate own products, stock, images | ❌ | ❌ | ✅ | ❌ |
| See orders containing own products (only own items + shipping name/address, never buyer email/phone/payment data) | ❌ | ❌ | ✅ | ✅ (all) |
| Update status of own items: paid → shipped → delivered | ❌ | ❌ | ✅ | ✅ |
| Reply publicly to reviews on own products (one reply per review) | ❌ | ❌ | ✅ | ❌ |
| Sales analytics of own shop | ❌ | ❌ | ✅ | ✅ (global) |
| Payout info (encrypted IBAN, shown masked) | ❌ | ❌ | ✅ (own) | ❌ (never sees full IBAN) |
| Manage users: list, view, lock/unlock, switch buyer↔seller, force password reset, reset 2FA | ❌ | ❌ | ❌ | ✅ |
| Approve / reject seller applications | ❌ | ❌ | ❌ | ✅ |
| Manage categories (CRUD) | ❌ | ❌ | ❌ | ✅ |
| Moderate products (deactivate / reactivate / delete) | ❌ | ❌ | ❌ | ✅ |
| Moderate reviews and seller replies (delete) | ❌ | ❌ | ❌ | ✅ |
| Cancel / refund any order (simulated) | ❌ | ❌ | ❌ | ✅ |
| Audit log viewer + CSV export | ❌ | ❌ | ❌ | ✅ |
| Security center | ❌ | ❌ | ❌ | ✅ |
| Profile, change password, 2FA, own login history | ❌ | ✅ | ✅ | ✅ |

Hard rules:
- Admin can never: see passwords or 2FA secrets, see a full IBAN, lock / demote / delete himself, create another admin from the web UI.
- Seller can never: buy, see other sellers' products/orders/revenue, change prices on existing orders.
- Buyer can never: access /seller or /admin, see other buyers' orders, review without purchase.
- Every rule enforced on the SERVER with access_control + Voters (ProductVoter, OrderVoter, ReviewVoter, ShopVoter, UserManagementVoter, SellerApplicationVoter). Hiding a button is not security.

New/changed entities (with migrations, validation, UUIDs in URLs): Category (replace the string category, migrate existing data), ProductImage (max 4 per product, same secure upload rules), Wishlist item, Address, Shop (1-1 with seller), SellerApplication (shopName, description, status pending/approved/rejected, reviewedBy, reviewedAt, reason), ReviewReply, NewsletterSubscriber. Order gets status `cancelled` and `refunded`; cancelling restores stock in a transaction.

## Phase R6 — Buyer dashboard (/buyer)

Sidebar: Overview · My orders · Wishlist · Addresses · My reviews · Become a seller · Profile · Security.
- **Overview**: welcome header, stat cards (orders, total spent, wishlist items, reviews), recent orders table with status badges, "Products to review" list, recommended products.
- **My orders**: list with filters by status; order detail page with timeline (paid → shipped → delivered), items, totals, shipping address, masked card (last 4), Cancel button only when status = paid.
- **Wishlist**: product cards, move to cart, remove.
- **Addresses**: CRUD, set default (max 5).
- **My reviews**: list, edit, delete.
- **Become a seller**: application form; shows status of current application (pending/approved/rejected + reason). When approved, the role switches to seller and a Shop is created.
- **Profile**: name, phone, avatar initials (DTO form).
- **Security**: change password (current password required), enable/disable 2FA with QR, login history (last 20 logins from audit log with date, IP, success/failure).

## Phase R7 — Seller dashboard (/seller)

Sidebar: Overview · Products · Orders · Reviews · Analytics · My shop · Payouts · Profile · Security.
- **Overview**: stat cards (revenue this month with % vs last month, units sold, pending shipments, active products, average rating), 30-day sales line chart, top 5 products, low-stock alerts (stock < 5), latest orders needing shipment, latest reviews.
- **Products**: table with thumbnail, name, category, price, stock, status toggle (active/inactive), sales count; create/edit form with live preview card, up to 4 images (upload or import from URL — SSRF protected), stock, category select; delete with confirm modal.
- **Orders**: only orders with my items; filters by status; detail page with my items only, shipping name + address; buttons Mark as shipped / Mark as delivered.
- **Reviews**: reviews on my products, filter by rating, reply once per review.
- **Analytics**: revenue by day (30 days), revenue by category (doughnut), best sellers table, conversion-free simple metrics. Date range filter (whitelisted presets: 7d, 30d, 90d).
- **My shop**: shop name, slug (unique, validated), description, logo upload; link to public shop page.
- **Payouts**: encrypted IBAN form (shown masked), simulated payout history computed from delivered orders.
- **Profile / Security**: same as buyer.

## Phase R8 — Admin dashboard (/admin)

Sidebar: Overview · Users · Seller applications · Products · Categories · Orders · Reviews · Audit log · Security center · Profile · Security.
- **Overview**: stat cards (users by role, new users this week, products, orders, revenue, pending seller applications, locked accounts, failed logins 24h), charts (orders per day 30d, revenue per day, users by role doughnut), latest audit events.
- **Users**: searchable paginated table (email, role badge, status, 2FA on/off, created, last login); user detail page (orders, products if seller, login history); actions: lock/unlock, switch role buyer↔seller, force password reset (sends reset email), reset 2FA. Cannot act on himself or other admins.
- **Seller applications**: pending list first; view details; approve / reject with reason (required on reject).
- **Products**: all products, filters (seller, category, status), deactivate / reactivate / delete.
- **Categories**: CRUD with name, slug, icon (choice from Bootstrap Icons list), position; cannot delete a category that has products.
- **Orders**: all orders, filters (status, date, buyer, seller), detail, cancel / refund (restores stock, transaction, audit log).
- **Reviews**: all reviews + seller replies, filter by rating/product, delete.
- **Audit log**: filters (action, user, IP, date range), pagination, CSV export (escape cells starting with = + - @ to prevent CSV injection).
- **Security center**: failed logins (24h/7d chart), top IPs with failures, currently locked accounts (with unlock), blocked uploads, blocked SSRF attempts, users without 2FA, security headers check list (static info).

## Phase R9 — Fixtures

Rebuild fixtures for the new model: 1 admin (admin@shop.test), 4 sellers each with a Shop, 8 buyers, 8 categories, 40 products with 1–4 generated images, addresses, wishlists, 60 orders in all statuses over the last 60 days (so charts look real), reviews + seller replies, 3 pending + 1 rejected seller applications, audit log history including failed logins. All passwords `Password2026!`. Print the account list at the end.

## Phase R10 — Access-control tests + final check

- PHPUnit WebTestCase tests proving the privilege matrix: for each role, request key routes of the other roles and assert 403/redirect; buyer cannot open another buyer's order (IDOR); seller cannot edit another seller's product; admin cannot lock himself; CSRF-less POST is rejected; price sent by client is ignored at checkout.
- Run `php bin/phpunit` and fix failures.
- Check every page in desktop and mobile widths for layout bugs, check the browser console for CSP errors and fix them.
- Update SECURITY.md (add the new features: seller applications, CSV injection, review replies, wishlist/address IDOR) and TESTING.md (a step-by-step checklist per role: Visitor, Buyer, Seller, Admin, with demo accounts).
- Final summary: what was built, demo accounts, how to run.
