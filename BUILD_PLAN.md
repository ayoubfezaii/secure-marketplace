# BUILD PLAN — Secure Marketplace (full platform)

Read CLAUDE.md first and follow all its security rules. Step 1 (User entity, register, login, rate limiter, lockout, base layout) is ALREADY DONE — reuse it, do not rebuild it.

Work through every phase below WITHOUT stopping to ask me questions. Make reasonable decisions yourself.
After each phase:
- run `php bin/console doctrine:migrations:migrate -n` if entities changed
- run `php bin/console lint:twig templates` and `php bin/console lint:container` and fix errors
- run `git add . && git commit -m "Phase X: <name>"`
- print one line: "PHASE X DONE"

Environment: Windows, PHP 8.2, Symfony 7.4, XAMPP MariaDB 10.4, server at https://127.0.0.1:8001, Mailpit on smtp://localhost:1025 (UI http://localhost:8025). UI: Bootstrap 5, clean and consistent, French or English labels (English is fine).

---

## Phase 2 — Password reset + 2FA
- symfonycasts/reset-password-bundle: token valid 1h, rate limited, identical response whether email exists or not (no user enumeration). MAILER_DSN=smtp://localhost:1025 in .env.local.
- scheb/2fa-bundle + scheb/2fa-totp: optional TOTP. User enables it from "Security settings" by scanning a QR code (endroid/qr-code or scheb's QR support) and confirming a 6-digit code. Once enabled, login requires the code. Can disable with password + code.
- "Forgot password?" link on login page.

## Phase 3 — Roles, AuditLog, security config
- Roles: ROLE_BUYER, ROLE_SELLER, ROLE_ADMIN. role_hierarchy: ROLE_ADMIN includes nothing else automatically except what is needed for admin pages.
- access_control: /admin -> ROLE_ADMIN, /seller -> ROLE_SELLER, /buyer -> ROLE_BUYER, /account -> IS_AUTHENTICATED_FULLY.
- AuditLog entity (id, user nullable, action, details JSON, ip, userAgent, createdAt) + AuditLogger service. Log: login success, login failure, lockout, logout, password reset requested/done, 2FA enabled/disabled, role change, user lock/unlock, product create/edit/delete, order placed, review posted/deleted, upload rejected, SSRF blocked.
- Safe redirect after login (_target_path internal only — open redirect protection).
- Console command `app:create-admin <email>` that creates an admin interactively (password asked hidden). This is the ONLY way to create an admin.
- After login, redirect each role to its own dashboard.

## Phase 4 — Products (seller)
- Product entity: id, uuid (public), seller (User), name (3-120), description (max 5000, plain text), price (decimal, >0, <=100000), stock (int >=0), category (simple string choice list), imageFilename nullable, isActive, createdAt, updatedAt.
- Seller CRUD under /seller/products, protected by ProductVoter (only owner can edit/delete; admin can deactivate). Delete via POST + CSRF.
- Image upload: jpg/png/webp only, check real MIME (finfo), max 2MB, re-encode/verify with getimagesize, random filename, stored in var/uploads/products (outside public/), served by a controller route /media/product/{filename} with strict filename regex and correct Content-Type + X-Content-Type-Options nosniff.
- "Import image from URL": https only, max 2MB, timeout 5s, no redirects, resolve host and block private/loopback/link-local/reserved IPv4+IPv6 ranges, then same validation as upload. Log blocked attempts.

## Phase 5 — Catalogue & search (public)
- / home: latest products. /products: list with search (name/description), category filter, min/max price, sort (whitelist: newest, price_asc, price_desc, name), pagination (12 per page). QueryBuilder + setParameter only.
- /products/{uuid}: product page with image, seller name, average rating, reviews, add to cart form.
- The search term is shown back on the page ("Results for ...") safely escaped.

## Phase 6 — Cart, checkout, orders
- CartItem entity (user, product, quantity 1-99). Add/update/remove via POST + CSRF. Buyers only. Cannot buy own products.
- Checkout page: shows summary computed from DB prices. Simulated payment form (card holder, fake card number validated with Luhn, expiry, CVV) — NEVER store card number or CVV; store only last 4 digits.
- Place order in a DB transaction with pessimistic lock on products: re-read price and stock from DB, check stock, decrement, create Order (uuid, buyer, total, status: paid/shipped/delivered/cancelled, createdAt) and OrderItem (product, seller, quantity, unitPrice snapshot). Ignore any price sent by the client.
- OrderVoter: buyer sees own orders; seller sees only orders containing their products (only their items); admin sees all.
- Seller can change status of their items paid -> shipped -> delivered (POST + CSRF).

## Phase 7 — Reviews
- Review entity: product, author, rating 1-5, comment (max 1000), createdAt. Only buyers who have a paid/delivered order containing the product, once per product. Author can delete own review; admin can delete any. Plain text only, auto-escaped (stored XSS impossible).

## Phase 8 — Profile & sensitive data
- /account/profile: edit display name, phone, using a DTO + explicit form (mass assignment protection: roles, email verification, lock fields never editable).
- Change password (requires current password).
- Sellers: IBAN field encrypted at rest with libsodium (sodium_crypto_secretbox), key generated and stored with `php bin/console secrets:set APP_ENCRYPTION_KEY` (document the command; for dev you may use .env.local). Show only masked IBAN (last 4).

## Phase 9 — DASHBOARDS
Shared: sidebar layout per role, cards with stats, Bootstrap tables, empty states.

### Buyer dashboard (/buyer)
- Stats cards: number of orders, total spent, items in cart, reviews written.
- Recent orders table (link to order detail), order status badges.
- "Products to review" list (purchased but not yet reviewed).
- Links: My cart, My orders, Security settings (2FA), Profile.

### Seller dashboard (/seller)
- Stats cards: active products, total units sold, revenue, pending shipments, average rating.
- Sales chart for the last 30 days (Chart.js via importmap, data passed as JSON with json_encode in a data attribute — no inline script with user data).
- Low stock alert (stock < 5).
- Recent orders containing my products with status update buttons.
- My products table with edit / delete / toggle active.
- Latest reviews on my products.

### Admin dashboard (/admin)
- Stats cards: users by role, products, orders, revenue, locked accounts, failed logins last 24h.
- Users management: list with search/pagination, lock/unlock, change role (buyer/seller only, cannot remove own admin), all POST + CSRF + audit log.
- Products moderation: list all, deactivate/reactivate.
- Orders: list all with filters by status.
- Reviews moderation: delete.
- Audit log viewer: filter by action, user, date; paginated.
- Security panel: recent failed logins, lockouts, blocked SSRF/upload attempts.

## Phase 10 — Hardening
- nelmio/security-bundle: CSP (default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self' plus Bootstrap source if CDN — prefer local assets via importmap/AssetMapper), X-Frame-Options DENY, frame-ancestors 'none', HSTS, X-Content-Type-Options nosniff, Referrer-Policy strict-origin-when-cross-origin. Make sure Chart.js and Bootstrap still work under the CSP.
- Session cookie: secure, httponly, samesite=lax. Session id regenerated on login.
- Custom error pages (403, 404, 500) without stack traces.
- .github/workflows/security.yml: composer install, composer audit, PHPStan level 6 (add phpstan.neon), php bin/console lint:twig, lint:yaml.

## Phase 11 — Demo data
- DoctrineFixturesBundle: 1 admin (admin@shop.test), 3 sellers, 5 buyers, 30 products with placeholder images generated locally (GD), some orders and reviews. All passwords: `Password2026!` — print them in the console. Command: `php bin/console doctrine:fixtures:load -n`.

## Phase 12 — Final review & SECURITY.md
- Review the whole code base against every rule in CLAUDE.md and fix gaps.
- Write SECURITY.md with a table: Vulnerability | Where prevented (file:line) | Defense | How to test (exact steps with browser / Burp / curl) | Expected result. Cover: SQLi, stored/reflected XSS, CSRF, IDOR, broken auth, brute force, file upload, SSRF, misconfiguration, sensitive data, mass assignment, vulnerable deps, logging, open redirect, clickjacking, price tampering, user enumeration.
- Write TESTING.md: a checklist for me to test every feature of every role (buyer, seller, admin) end to end.
- Print a final summary: routes list (`php bin/console debug:router`), demo accounts, and how to run the project.
