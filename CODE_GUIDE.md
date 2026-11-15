# CODE_GUIDE.md — where everything is

A reading map of the Secure Marketplace code (Symfony 7.4, PHP 8.2, Twig, Doctrine, MariaDB).
Use it to find the file, class and line to open when asked "where is X prevented?".

- Line numbers are those of the commit that added this file. If a file has been edited since, search for the
  method name or for the `// SECURITY:` comment next to the line.
- `SECURITY.md` has the other half of the story: how to **test** each defense and the expected result.
- "Rule N" refers to the numbered security rules in `CLAUDE.md`.

---

## 1. Project map

### `src/Controller` — one class per group of pages; receives the request, checks access, calls a service, renders Twig

| File | What it does |
|---|---|
| `SecurityController.php` | Login form and POST-only logout (the checks themselves are done by the firewall). |
| `RegistrationController.php` | Sign-up: rate limited, always creates a `ROLE_BUYER`, hashes the password. |
| `ResetPasswordController.php` | Forgot / reset password with the same answer whether the email exists or not. |
| `SecuritySettingsController.php` | Enable / confirm / disable TOTP 2FA, login history. |
| `ProfileController.php` | Edit profile through `ProfileDto`, change password (needs the current one). |
| `DashboardController.php` | `/dashboard`: redirects each role to its own dashboard. |
| `HomeController.php`, `CatalogController.php`, `ShopController.php` | Public pages: home, product search / product page, public shop page. |
| `CartController.php` | Cart add / update / remove: POST + CSRF, lines found by (logged-in buyer, product). |
| `CheckoutController.php` | 3-step checkout (address, payment, review) then `place()`, which calls `CheckoutService`. |
| `OrderController.php` | Buyer order list, order page, cancel, order-item status change (all through `OrderVoter`). |
| `ReviewController.php` | Post a review (must have bought the product) and delete one. |
| `WishlistController.php` | Wishlist toggle from the shop pages. |
| `MediaController.php` | Serves uploaded images from outside `public/` with a strict filename pattern. |
| `NewsletterController.php` | Newsletter sign-up: CSRF + rate limit. |
| `Buyer/*` | Buyer dashboard: `DashboardController`, `AddressController`, `ReviewController`, `WishlistController`, `SellerApplicationController`. |
| `Seller/*` | Seller dashboard: `DashboardController` (+ analytics), `ProductController` (CRUD, upload, import from URL), `OrderController`, `ReviewController`, `ShopController` (shop + encrypted IBAN). |
| `Admin/*` | Admin panel: `DashboardController`, `UserController`, `ApplicationController`, `CategoryController`, `ModerationController` (products, orders, reviews), `AuditController` (audit log, CSV export, security center). |

### `src/Entity` — Doctrine entities (one class = one table) with Validator constraints

| File | What it does |
|---|---|
| `User.php` | Account: email, password hash, single exclusive role, lockout counters, 2FA secret, encrypted IBAN. |
| `Product.php`, `ProductImage.php`, `Category.php` | A listing, its images (max 4) and its category; price and stock validation. |
| `CartItem.php` | Cart line. Has **no price column** on purpose (price tampering). |
| `Order.php`, `OrderItem.php` | An order and its lines; unit price is a snapshot from the database; status state machines. |
| `Review.php`, `ReviewReply.php` | Buyer review (one per product and buyer) and the seller's single reply. |
| `Address.php`, `WishlistItem.php` | Buyer shipping addresses and saved products. |
| `Shop.php`, `SellerApplication.php` | Seller's public shop; a buyer's request to become a seller. |
| `AuditLog.php` | One security event (action, user, IP, user agent, details) + the list of action names. |
| `ResetPasswordRequest.php`, `NewsletterSubscriber.php` | Hashed reset tokens; newsletter emails. |

`src/Repository/*` holds the queries for each entity (QueryBuilder + `setParameter()` only). `src/Enum/` holds
`PaymentMethod` and `PaymentStatus`.

### `src/Security` — authentication helpers and access rules

| File | What it does |
|---|---|
| `Voter/ProductVoter.php` | Who may view / edit / delete / toggle / moderate a product. |
| `Voter/OrderVoter.php` | Who may view, cancel, refund an order, confirm cash, or move an order item forward. |
| `Voter/ReviewVoter.php` | Who may create, edit, delete a review, reply to it, or delete a reply. |
| `Voter/AddressVoter.php`, `Voter/ShopVoter.php` | Only the owner may edit / delete an address or edit a shop. |
| `Voter/SellerApplicationVoter.php` | Buyers apply (one pending at a time); admins view and review. |
| `Voter/UserManagementVoter.php` | Admins manage users, never themselves and never another admin. |
| `UserChecker.php` | Refuses locked, blocked or multi-role accounts **before** the password is checked. |
| `LoginSuccessHandler.php` | After login: follows `_target_path` only if it is internal, else the role's dashboard. |
| `SafeRedirect.php` | `isSafePath()`: the single open-redirect check. |
| `PasswordPolicy.php` | Password constraints shared by all password forms (12+ chars, not breached). |

`src/EventSubscriber/` completes this folder: `LoginLockoutSubscriber` (counts failures, locks, logs logins),
`TwoFactorSubscriber` (2FA rate limit + log), `LockedUserSubscriber` (ends sessions of blocked users),
`AccessDeniedSubscriber` (logs every 403), `ResponseHardeningSubscriber` (removes `X-Powered-By`).

### `src/Service` — business logic, called by controllers

| File | What it does |
|---|---|
| `CheckoutService.php` | Places an order in one DB transaction: locks products, re-reads price and stock, snapshots prices. |
| `OrderManager.php` | Cancel, refund, cash collected / refused, each in a locked transaction with stock restored once. |
| `CashOnDeliveryPolicy.php` | Limits on cash orders (max total, max pending, single shop). |
| `ImageStorage.php` | Upload validation: size, real MIME type, re-encoding, random name, stored outside `public/`. |
| `SafeImageFetcher.php` | "Import image from URL" with all the SSRF checks. |
| `FieldEncryptor.php` | libsodium encryption / decryption of the seller IBAN. |
| `AuditLogger.php` | Writes one `AuditLog` row + one Monolog line per security event. |
| `CsvSanitizer.php` | Neutralises spreadsheet formulas in exported CSV cells. |
| `AccountManager.php` | Role switches and seller-application decisions, in a transaction and audit-logged. |
| `ListQuery.php`, `Pagination.php` | Whitelisted sort / search / paging for dashboard tables. |
| `DashboardStats.php`, `SellerAnalytics.php`, `AdminStats.php` | Figures for the three dashboards (seller queries are always scoped to the seller). |
| `DashboardNavigation.php`, `NotificationProvider.php` | Sidebar menu and notifications computed from the user's role. |
| `Money.php`, `ProductRatingUpdater.php`, `PlaceholderImageGenerator.php` | Dinar formatting, rating average, demo images for fixtures. |

### `src/Form` — form types: the explicit list of fields a request may fill (mass assignment)

| File | What it does |
|---|---|
| `RegistrationFormType.php` | Name, email, password, terms. No role field. |
| `ProductFormType.php` | Product fields + image files + image URL. No seller / owner field. |
| `CheckoutFormType.php` | Payment method and card fields only. No price or total. |
| `ProfileFormType.php`, `IbanFormType.php` | Bound to `ProfileDto` / `IbanDto`, not to `User`. |
| `ChangePasswordFormType.php`, `NewPasswordFormType.php`, `ResetPasswordRequestFormType.php` | Password forms using `PasswordPolicy`. |
| `ReviewFormType.php`, `AddressFormType.php`, `ShopFormType.php`, `CategoryFormType.php`, `SellerApplicationFormType.php` | One form per object, each with its own CSRF token id. |

### `src/Dto` — small objects that sit between a request and an entity

| File | What it does |
|---|---|
| `ProfileDto.php` | Only `displayName` and `phone` can be changed from the profile form. |
| `IbanDto.php` | IBAN (validated) and a "remove" checkbox. |
| `PaymentDto.php` | Payment method + card data; validates (Luhn, expiry) then erases number and CVV. |
| `ProductSearchCriteria.php` | Catalogue filters read from the URL; sort key checked against a whitelist. |

### `templates` — Twig views (auto-escaping on, no `raw` filter)

| Path | What it holds |
|---|---|
| `base.html.twig` | HTML skeleton; importmap script with the CSP nonce. |
| `layouts/` | `dashboard.html.twig` (shared shell of the 3 dashboards), `auth.html.twig`, sidebar, header, footer, confirm modal. |
| `_ui/`, `_partials/` | Reusable macros (forms, tables, delete form with CSRF token) and fragments (product card, pager, badges). |
| `security/`, `registration/`, `reset_password/`, `account/` | Login, 2FA code, security settings, sign-up, password reset, profile. |
| `home/`, `catalog/`, `shop/`, `cart/`, `checkout/` | Public shop and buying flow. |
| `buyer/`, `seller/`, `admin/` | Pages of the three dashboards (section 4). |
| `bundles/TwigBundle/Exception/` | Custom 403 / 404 / 500 pages with no stack trace. |

### `assets` — JavaScript and CSS served locally by AssetMapper (no CDN, no inline script)

| File | What it does |
|---|---|
| `app.js`, `stimulus_bootstrap.js` | Entry point; starts the Stimulus controllers. |
| `controllers/csrf_protection_controller.js` | Symfony's CSRF helper for forms. |
| `controllers/confirm_controller.js` | Confirmation modal before a delete; the form stays a POST with its token. |
| `controllers/chart_controller.js` | Draws charts from JSON in a `data-*` attribute (no inline script). |
| `controllers/payment_method_controller.js` | Shows / hides the card fields (display only; the server decides). |
| `controllers/password_strength_controller.js`, `password_toggle_controller.js` | Client-side password meter and show / hide. |
| `money.js`, `styles/` | Dinar formatting in the browser; all CSS, fonts and icons. |

### `config/packages` — configuration

| File | What it does |
|---|---|
| `security.yaml` | Password hashing, firewall, login form, throttling, 2FA, logout, `role_hierarchy`, `access_control`. |
| `nelmio_security.yaml` | CSP, `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy, Permissions-Policy, HSTS. |
| `csrf.yaml` | Turns on session-based CSRF tokens for forms. |
| `rate_limiter.yaml` | Limits for reset, 2FA, password change, registration, cash orders, newsletter. |
| `scheb_2fa.yaml` | TOTP settings and code replay protection. |
| `reset_password.yaml` | Reset link lifetime (1 h) and one mail per 15 min. |
| `framework.yaml` | Session cookie flags (Secure, HttpOnly, SameSite=Lax), `http_method_override: false`. |
| `twig.yaml`, `monolog.yaml`, `doctrine.yaml` | Twig (auto-escape left on), logging, database. |

### `tests` — PHPUnit (`php bin/phpunit`)

| File | What it checks |
|---|---|
| `AppWebTestCase.php` | Base class: logs in as a demo account. |
| `Security/PrivilegeMatrixTest.php` | Each role against each area (buyer on `/admin` → 403, ...). |
| `Security/ObjectAccessTest.php` | IDOR: another user's order, address, product. |
| `Security/CsrfTest.php` | POST without token refused; GET on POST-only routes → 405. |
| `Security/CheckoutPriceTamperingTest.php` | A price sent by the client is ignored at checkout. |
| `Security/CashOnDeliveryTest.php`, `Security/CurrencyTest.php` | Cash-on-delivery rules; price format and currency. |
| `Unit/CsvSanitizerTest.php`, `Unit/MoneyTest.php` | CSV formula neutralisation; money formatting. |

---

## 2. Path of a request: browser → database

Example: a seller deletes one of their products — `POST /seller/products/{uuid}/delete`.

| Step | Where | What happens |
|---|---|---|
| 1. Front controller | `public/index.php` | Every request enters here; Symfony builds the `Request`. |
| 2. Response headers | `config/packages/nelmio_security.yaml` | CSP, `X-Frame-Options`, `nosniff`, HSTS are attached to the response of every request. |
| 3. Firewall | `config/packages/security.yaml:34` (`main`) | Loads the user from the session. `UserChecker` (`:39`) and `LockedUserSubscriber` refuse locked / blocked accounts. A user who has not finished 2FA only holds a half-authenticated token. |
| 4. `access_control` | `config/packages/security.yaml:98-115` | First matching rule wins: `^/seller` needs `ROLE_SELLER` (`:109`). A buyer stops here with a 403. |
| 5. Routing | `src/Controller/Seller/ProductController.php:173` | The route accepts **POST only** (a GET gets 405) and the `{uuid}` must be a UUID v4. |
| 6. Controller role check | `ProductController.php:41` | `#[IsGranted('ROLE_SELLER')]` on the class: a second layer if `access_control` is ever changed. |
| 7. Entity lookup | `ProductController.php:174` (`#[MapEntity]`) | Doctrine finds the product by UUID with a bound parameter; unknown UUID → 404. |
| 8. CSRF check | `ProductController.php:177` | `isCsrfTokenValid('delete-product-<uuid>', _token)`; wrong or missing token → 403. |
| 9. Voter | `ProductController.php:181` → `src/Security/Voter/ProductVoter.php:53` | `denyAccessUnlessGranted(ProductVoter::EDIT, $product)`: true only if the logged-in seller owns this product (`:62`). Another seller's product → 403, logged by `AccessDeniedSubscriber`. |
| 10. Service | `ImageStorage::delete()`, `AuditLogger::log()` (`src/Service/AuditLogger.php:54`) | Image files are removed and a `product.deleted` event is written. |
| 11. Doctrine | `EntityManager::remove()` + `flush()` | Doctrine sends a prepared `DELETE` statement to MariaDB; values are never concatenated into SQL. |
| 12. Response | `redirectToRoute('seller_products')` | Redirect to a named internal route (no open redirect). |

Two variations worth knowing:

- **Form pages** (new / edit product): the form type lists the allowed fields and carries its own CSRF token;
  `$form->isValid()` runs the Validator constraints of the entity or DTO before anything is saved.
- **Checkout**: the service step is `CheckoutService::placeOrder()` (`src/Service/CheckoutService.php:75`), which
  opens a transaction (`:93`), locks the product rows with `SELECT ... FOR UPDATE` (`:199`) and reads price and
  stock from those rows.

---

## 3. Attack → File → Class / method → Line

| Attack | File | Class / method | Line |
|---|---|---|---|
| **SQL injection** | `src/Repository/ProductRepository.php` | `ProductRepository::search()` — QueryBuilder, `setParameter()`, LIKE wildcards escaped | 76, 90 |
| | `src/Dto/ProductSearchCriteria.php` | `SORTS` whitelist and `fromRequest()` (ORDER BY cannot be a parameter) | 31, 88 |
| | `src/Service/ListQuery.php` | `ListQuery::fromRequest()` — sort whitelist for dashboard tables | 54, 58 |
| **XSS** | `config/packages/twig.yaml` | Twig auto-escaping left on (no `raw` filter on user data anywhere) | 4 |
| | `templates/catalog/show.html.twig` | Product description and review text printed as escaped plain text | 137, 209 |
| | `config/packages/nelmio_security.yaml` | CSP `script-src 'self'` (second layer: inline scripts blocked) | 35, 44 |
| **CSRF** | `config/packages/csrf.yaml` | CSRF tokens on every Symfony form | 6 |
| | `config/packages/security.yaml` | `enable_csrf` on login, 2FA and logout | 48, 71, 81 |
| | `src/Controller/Seller/ProductController.php` | `ProductController::delete()` — `isCsrfTokenValid()`, POST-only route | 173, 177 |
| | `src/Controller/CartController.php` | `CartController::assertCsrf()` | 170 |
| **IDOR** | `src/Security/Voter/OrderVoter.php` | `OrderVoter::voteOnAttribute()` — buyer of the order / seller of the item | 62, 78, 84 |
| | `src/Security/Voter/ProductVoter.php` | `ProductVoter::voteOnAttribute()` — owner check | 53, 62 |
| | `src/Security/Voter/ReviewVoter.php` | `ReviewVoter::voteOnAttribute()` — author check | 62, 76 |
| | `src/Controller/OrderController.php` | `OrderController::show()` — UUID in the URL + `denyAccessUnlessGranted` | 69, 73 |
| | `src/Repository/CartItemRepository.php` | `findOneForUserAndProduct()` — cart lines scoped to the logged-in buyer | 49 |
| **Privilege escalation** | `config/packages/security.yaml` | `role_hierarchy` (no inheritance) and `access_control` by area | 92, 108-112 |
| | `src/Entity/User.php` | `User::setRoles()` — exactly one known role | 207 |
| | `src/Controller/RegistrationController.php` | `register()` — role set by the server to `ROLE_BUYER` | 71 |
| | `src/Controller/Admin/UserController.php` | `changeRole()` — whitelist buyer / seller, never admin | 181 |
| | `src/Security/Voter/UserManagementVoter.php` | `voteOnAttribute()` — never on yourself or another admin | 49, 61 |
| **Mass assignment** | `src/Form/ProductFormType.php` | `ProductFormType::buildForm()` — explicit fields, no seller | 36, 59 |
| | `src/Dto/ProfileDto.php` | `ProfileDto` — only name and phone | 21 |
| | `src/Controller/ProfileController.php` | `profile()` — explicit copy from the DTO to the user | 57, 64 |
| | `src/Form/RegistrationFormType.php` | `RegistrationFormType` — no role / isVerified field | 25 |
| **Brute force** | `config/packages/security.yaml` | `login_throttling` — 5 tries / 15 min | 59 |
| | `src/EventSubscriber/LoginLockoutSubscriber.php` | `onLoginFailure()` — counts the failure | 61, 89 |
| | `src/Entity/User.php` | `User::registerFailedLogin()` — lock after 5 (`failedAttempts`, `lockedUntil`) | 274 |
| | `src/Security/UserChecker.php` | `checkPreAuth()` — locked account refused before the password check | 32, 50 |
| | `config/packages/rate_limiter.yaml` | Limiters for reset, 2FA, password change, registration | 6-35 |
| | `src/EventSubscriber/TwoFactorSubscriber.php` | `onAttempt()` — 2FA codes limited per account | 53 |
| **Password hashing** | `config/packages/security.yaml` | `password_hashers` — `algorithm: argon2id` | 12-16 |
| | `src/Controller/RegistrationController.php` | `register()` — `hashPassword()`, plain text never stored | 73 |
| | `src/Security/PasswordPolicy.php` | `PasswordPolicy::constraints()` — 12+ chars, breached-password check | 29 |
| **User enumeration** | `src/Controller/ResetPasswordController.php` | `request()` — same redirect whether the email exists or not | 95 |
| | `src/Controller/ResetPasswordController.php` | `checkEmail()` — always the same text; mail errors only logged | 109, 221 |
| | `templates/security/login.html.twig` | Generic "Invalid credentials." for unknown email and wrong password | 17 |
| **Price tampering** | `src/Service/CheckoutService.php` | `CheckoutService::placeOrder()` — one transaction, cart read from DB | 75, 93 |
| | `src/Service/CheckoutService.php` | `lockProducts()` — `PESSIMISTIC_WRITE` (`SELECT ... FOR UPDATE`) | 184, 199 |
| | `src/Service/CheckoutService.php` | Stock check on the locked row | 132 |
| | `src/Entity/OrderItem.php` | `OrderItem::__construct()` — unit price copied from the product row | 99 |
| | `src/Entity/CartItem.php` | `CartItem` — no price column | 19 |
| **File upload** | `src/Service/ImageStorage.php` | `ImageStorage::validateAndStore()` | 88 |
| | | 2 MB limit / MIME whitelist | 34, 39 |
| | | Real MIME (`finfo`) / `getimagesize` / GD re-encode / random name | 97, 105, 117, 127 |
| | `src/Controller/MediaController.php` | `MediaController::product()` — files served by a controller, strict filename regex | 36 |
| **SSRF** | `src/Service/SafeImageFetcher.php` | `SafeImageFetcher::validateUrl()` — https only, port 443, no credentials | 111, 125 |
| | | `isPublicIp()` — private, loopback, link-local ranges blocked | 173, 179 |
| | | `resolve()` — DNS A + AAAA, every address checked | 195 |
| | | `download()` — pinned IP, `max_redirects: 0`, 5 s timeout | 223, 234-238 |
| | | `NoPrivateNetworkHttpClient` wrapper (second layer) | 68 |
| **Open redirect** | `src/Security/SafeRedirect.php` | `SafeRedirect::isSafePath()` — internal paths only | 28 |
| | `src/Security/LoginSuccessHandler.php` | `onAuthenticationSuccess()` / `readTarget()` | 55, 76, 113 |
| | `config/packages/security.yaml` | `use_referer: false` | 55 |
| **Clickjacking** | `config/packages/nelmio_security.yaml` | `clickjacking` — `X-Frame-Options: DENY` | 4-6 |
| | `config/packages/nelmio_security.yaml` | CSP `frame-ancestors 'none'` | 52 |
| **Sensitive data encryption** | `src/Service/FieldEncryptor.php` | `FieldEncryptor::encrypt()` / `decrypt()` — `sodium_crypto_secretbox` | 52, 58, 75 |
| | `src/Controller/Seller/ShopController.php` | `ShopController::payouts()` — IBAN encrypted before it reaches the entity | 119, 134 |
| | `src/Entity/User.php` | `ibanEncrypted` column; removed from session data in `__serialize()` | 113, 482 |
| **CSV injection** | `src/Service/CsvSanitizer.php` | `CsvSanitizer::cell()` — prefixes cells starting with `= + - @` TAB CR | 23, 34 |
| | `src/Controller/Admin/AuditController.php` | `AuditController::export()` — every row through `CsvSanitizer::row()` | 65, 92 |
| **Vulnerable dependencies** | `.github/workflows/security.yml` | CI steps `composer audit`, `importmap:audit`, PHPStan | 43, 46, 52 |
| **Audit log** | `src/Service/AuditLogger.php` | `AuditLogger::log()` — DB row + Monolog | 54 |
| | `src/Entity/AuditLog.php` | `AuditLog` — action name constants | 30 |
| | `src/EventSubscriber/LoginLockoutSubscriber.php` | `onLoginFailure()` / `onLoginSuccess()` / `onLogout()` | 61, 107, 130 |
| | `src/EventSubscriber/AccessDeniedSubscriber.php` | `onException()` — every 403 of a logged-in user | 51, 65 |
| | `src/Controller/Admin/AuditController.php` | `audit()` — admin viewer with filters | 46 |

---

## 4. The three dashboards

All three share one shell: `templates/layouts/dashboard.html.twig` + `templates/layouts/_sidebar.html.twig`, with the
menu built by `src/Service/DashboardNavigation.php` from the logged-in user's role. `/dashboard`
(`src/Controller/DashboardController.php`) only redirects to the right one.

### Buyer dashboard — `/buyer` (`ROLE_BUYER`)

| | |
|---|---|
| **Controller** | `src/Controller/Buyer/DashboardController.php` (home page of the dashboard) |
| Other controllers | `src/Controller/Buyer/AddressController.php`, `ReviewController.php`, `WishlistController.php`, `SellerApplicationController.php`, and `src/Controller/OrderController.php` for `/buyer/orders` |
| **Templates** | `templates/buyer/` |
| **Voters** | `OrderVoter` (`ORDER_VIEW`, `ORDER_CANCEL`), `AddressVoter` (`ADDRESS_EDIT`, `ADDRESS_DELETE`), `ReviewVoter` (`REVIEW_CREATE`, `REVIEW_EDIT`, `REVIEW_DELETE`), `SellerApplicationVoter` (`SELLER_APPLY`) |
| Without a voter | Dashboard figures and the wishlist have no object id in the URL: their queries are scoped to the logged-in buyer. |

### Seller dashboard — `/seller` (`ROLE_SELLER`)

| | |
|---|---|
| **Controller** | `src/Controller/Seller/DashboardController.php` (dashboard + analytics) |
| Other controllers | `src/Controller/Seller/ProductController.php`, `OrderController.php`, `ReviewController.php`, `ShopController.php` (shop + payouts / IBAN) |
| **Templates** | `templates/seller/` (+ `templates/order/_seller_table.html.twig`) |
| **Voters** | `ProductVoter` (`PRODUCT_EDIT`), `OrderVoter` (`ORDER_VIEW`, `ORDER_CASH_COLLECT`, `ORDER_CASH_REFUSE`, `ORDER_ITEM_UPDATE_STATUS`), `ReviewVoter` (`REVIEW_REPLY`), `ShopVoter` (`SHOP_EDIT`) |
| Without a voter | Dashboard, analytics and payouts take no object id: every query is bound to the logged-in seller (`SellerAnalytics`). A new product gets its owner from the session, not from the form. |

### Admin dashboard — `/admin` (`ROLE_ADMIN`)

| | |
|---|---|
| **Controller** | `src/Controller/Admin/DashboardController.php` (overview) |
| Other controllers | `src/Controller/Admin/UserController.php`, `ApplicationController.php`, `CategoryController.php`, `ModerationController.php`, `AuditController.php` |
| **Templates** | `templates/admin/` |
| **Voters** | `UserManagementVoter` (`USER_VIEW`, `USER_MANAGE`), `SellerApplicationVoter` (`SELLER_APPLICATION_VIEW`, `SELLER_APPLICATION_REVIEW`), `ProductVoter` (`PRODUCT_MODERATE`), `OrderVoter` (`ORDER_VIEW`, `ORDER_ADMIN_CANCEL`, `ORDER_REFUND`), `ReviewVoter` (`REVIEW_REPLY_DELETE`, `REVIEW_DELETE`) |
| Without a voter | Categories, the audit log, the CSV export and the security center are protected by the role only (`access_control` + `#[IsGranted('ROLE_ADMIN')]`). |

Every state-changing action in the three dashboards is a POST-only route with its own CSRF token, checked in the
controller next to the voter call.
