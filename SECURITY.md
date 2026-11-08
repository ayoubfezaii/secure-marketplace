# SECURITY.md: Secure Marketplace

Each row maps a vulnerability to the code that defends against it and to a test you can repeat.
Rule numbers refer to `CLAUDE.md`. Comments marked `// SECURITY:` / `# SECURITY` in the code explain each line.

## Step 1: Authentication

| Vulnerability | Where | Defense | How to test |
|---|---|---|---|
| Password cracking after a DB leak | `config/packages/security.yaml` (`password_hashers`), `src/Controller/RegistrationController.php` | Argon2id hashing (memory-hard), set explicitly because Symfony 7's `auto` picks bcrypt. `migrate_from: bcrypt` upgrades old hashes on login (`UserRepository::upgradePassword`). Rule 5 | Register, then `php bin/console dbal:run-sql "SELECT email, password FROM \`user\`"`: the hash starts with `$argon2id$`. |
| Weak passwords | `src/Form/RegistrationFormType.php` (`plainPassword` constraints) | Server-side `Length(min 12, max 4096)` + `Regex` for at least one letter and one digit. Rule 15 | Register with `abcdefghijkl` (no digit) or `abc123` (too short): form error. Remove `minlength` in DevTools: still rejected server side. |
| Invalid / duplicate emails | `src/Entity/User.php` (`email`, `UniqueEntity`, unique index) | `Email(strict)` + `Length(180)`, normalised to lowercase, `UniqueEntity` + DB unique index. Rule 15 | Register `Test@Example.com` and then `test@example.com`: "already registered". |
| Privilege escalation at registration (mass assignment) | `RegistrationFormType.php` (`accountType`), `RegistrationController.php` (`ACCOUNT_TYPE_TO_ROLE`) | Only 3 form fields; `roles`/`isVerified` aren't fields (extra fields are rejected); account type limited to `buyer`/`seller` and mapped to a role through a fixed whitelist. Rule 9 | DevTools: change a radio `value` to `admin` → "The selected choice is invalid". Add `<input name="registration_form[roles][]" value="ROLE_ADMIN">` → "This form should not contain extra fields." |
| CSRF on registration / login / logout | `config/packages/csrf.yaml`, `security.yaml` (`enable_csrf`), `templates/security/login.html.twig`, `templates/base.html.twig` | Session-based CSRF tokens on all three; logout is POST-only (`GET /logout` → 405). Rule 3 | Change the hidden `_csrf_token` in DevTools and submit → "Invalid CSRF token." / 403 on logout. |
| Online brute force (password guessing) | `security.yaml` (`login_throttling`) | Rate limiter: 5 attempts / 15 min per IP + email, 25 / 15 min per IP. `X-Forwarded-For` is ignored (no trusted proxies), so it can't be spoofed. Rule 6 | 5 wrong passwords → 6th attempt: "Too many failed login attempts, please try again in 15 minutes." |
| Distributed brute force (many IPs) | `src/Security/UserChecker.php`, `src/EventSubscriber/LoginLockoutSubscriber.php`, `User::registerFailedLogin()` | Lockout stored on the account: after 5 consecutive wrong passwords `lockedUntil = now + 15 min`; checked **before** the password, so even the right password is refused. Reset on success. Rule 6 | 5 wrong passwords on `http://127.0.0.1:8000`, then the correct one on `http://localhost:8000` (different IP, `::1`): "Too many failed login attempts. Please try again later." |
| User enumeration via login | Symfony default (`expose_security_errors: none`) | Unknown email and wrong password both return "Invalid credentials.". | Try a non-existent email and a wrong password: same message. |
| Session fixation | `security.yaml` (`session_fixation_strategy: migrate`) | New session id on login. Rule 5 | DevTools → Application → Cookies: `PHPSESSID` changes after login. |
| Open redirect after login | `security.yaml` (`use_referer: false`), Symfony `HttpUtils` domain check | Only same-host targets are honoured; external URLs fall back to `/`. Rule 12 | `/login?_target_path=https://evil.example` → log in → you land on `/`. |
| XSS in displayed data | Twig auto-escaping (default, never disabled) | Output escaped; no `raw`. Rule 2 | Shown in later steps (product names/reviews). |
| Logging of auth events | `LoginLockoutSubscriber.php`, `RegistrationController.php` | Monolog `security` channel: success, failure, block reason, lockout, registration. Rule 14 (AuditLog entity comes later) | Read `var/log/dev.log` and search for `security.` |

## Known limitations (documented trade-offs)

- **Registration reveals whether an email exists** ("already registered"). Avoiding this needs an email-confirmation flow (planned with `isVerified`).
- **The lockout message differs from the rate-limiter message**, so an attacker who changes IP could tell the account exists. Accepted because the lockout can't be bypassed.
- **Account lockout can be abused to lock a victim out (DoS)** for 15 minutes. Mitigated by the short duration; the rate limiter slows the attacker down.
- **`isVerified` is not enforced at login yet**: there is no email verification flow so far.
- **Local DB uses `root` with no password** (XAMPP dev only, `.env.local`, never committed). Production needs a dedicated least-privilege user.

## Phase 2: Password reset + 2FA

| Vulnerability | Where | Defense | How to test |
|---|---|---|---|
| User enumeration via "forgot password" | `src/Controller/ResetPasswordController.php::request()` | Same redirect + same "check your email" page whether the email exists or not, and whether the per-account throttle was hit. Mail errors only logged. | Submit a known and an unknown email: both `302 → /reset-password/check-email`, identical page. Mailpit (http://localhost:8025) shows one mail. |
| Reset-token theft / reuse | `config/packages/reset_password.yaml`, `src/Entity/ResetPasswordRequest.php` | Token valid 1 h, single use (deleted before the password changes), only a selector + hash stored in DB, token moved from URL to session then redirected (no Referer leak). | Use a reset link twice: the second time → "invalid or has expired". |
| Mail bombing / reset brute force | `config/packages/rate_limiter.yaml` (`password_reset_request`, `password_reset_token`), `throttle_limit: 900` | 5 requests / 15 min per IP; 1 email per account per 15 min; 10 token checks / 15 min per IP. Rule 6 | Submit the form 6 times quickly → "Too many requests". |
| Weak new password | `src/Security/PasswordPolicy.php` | One shared policy (12+ chars, letter + digit, max 4096, `NotCompromisedPassword`) for registration and reset. Rule 15 | Reset with `password1234` → "This password has been leaked". |
| Credential stuffing (stolen password) | `config/packages/security.yaml` (`two_factor`), `src/Entity/User.php` (TOTP), `config/packages/scheb_2fa.yaml` | Optional TOTP 2FA (RFC 6238). Half-authenticated sessions can only reach `/2fa` (`IS_AUTHENTICATED_2FA_IN_PROGRESS`). Rule 5 | Enable 2FA, log in with the password, then open `/account/security` directly → redirected to `/2fa`. |
| 2FA code brute force | `src/EventSubscriber/TwoFactorSubscriber.php` | 5 code attempts / 5 min **per account** (not per IP/session), checked before the code. Rule 6 | Enter 5 wrong codes → "Too many invalid codes"; even the right code is then refused for 5 min. |
| 2FA code replay | `scheb_2fa.yaml` (`code_reuse_cache`) | A code already used is rejected for 90 s. | Log in with a code, log out, log in again with the same code → "This 2FA code has already been used." |
| Enabling 2FA with a wrong secret / disabling via stolen session | `src/Controller/SecuritySettingsController.php` | Secret kept in session until a valid code confirms it. Disabling needs current password **and** a valid code. All actions POST + CSRF. Rules 3, 5 | Disable with a wrong password → still "Enabled". `GET /account/security/2fa/disable` → 405. |
| TOTP secret exposure | `User::__serialize()`, `SecuritySettingsController::index()` | Secret stripped from serialized session data; QR code generated server side (no third-party QR API), shown only during enrolment. | Inspect `var/sessions`: no `totpSecret` value. |
| Login CSRF on 2FA form | `security.yaml` (`two_factor.enable_csrf`) | CSRF token on the code form. Rule 3 | Change the hidden `_csrf_token` → "Invalid CSRF token." |

## Phase 3: Roles, AuditLog, security config

| Vulnerability | Where | Defense | How to test |
|---|---|---|---|
| Vertical privilege escalation | `config/packages/security.yaml` (`access_control`, `role_hierarchy`), `#[IsGranted]` on every dashboard controller | `/admin` → ROLE_ADMIN, `/seller` → ROLE_SELLER, `/buyer` → ROLE_BUYER, `/account` → fully authenticated. Roles don't inherit each other (least privilege). Two layers: firewall + controller attribute. Rule 4 | Log in as a buyer and open `/admin` or `/seller` → 403. |
| Admin creation through the web | `src/Command/CreateAdminCommand.php` | `php bin/console app:create-admin <email>` is the only way to get ROLE_ADMIN. Hidden password prompt, refuses non-interactive mode (no password in shell history), refuses existing emails (no silent promotion), same password policy. Rule 9 | `php bin/console app:create-admin x@y.z -n` → "must be run interactively". |
| Open redirect after login | `src/Security/SafeRedirect.php`, `src/Security/LoginSuccessHandler.php` | `_target_path` (form, query, or firewall-stored) must be a relative path starting with a single `/`; rejects `//host`, `/\host`, schemes, control characters. Otherwise → role dashboard. Applied after password AND after 2FA. Rule 12 | `curl` the login with `_target_path=//evil.example` (in Git Bash set `MSYS_NO_PATHCONV=1`) → `Location: /buyer`. |
| Missing audit trail | `src/Entity/AuditLog.php`, `src/Service/AuditLogger.php`, `src/EventSubscriber/LoginLockoutSubscriber.php`, `TwoFactorSubscriber.php`, `AccessDeniedSubscriber.php` | Append-only `audit_log` table (read-only entity, inserted via DBAL with bound params) + Monolog `security` channel. Logs login success/failure/blocked, lockout, logout, registration, reset requested/done/invalid, 2FA on/off/success/failure, rate limiting, 403s hit by logged-in users (IDOR probing). Rule 14 | Fail a login, then `php bin/console dbal:run-sql "SELECT action, details FROM audit_log ORDER BY id DESC LIMIT 5"`. |
| Log injection / stored XSS via User-Agent | `AuditLogger::log()` | UA truncated to 255 chars, stored with a bound parameter, auto-escaped in the admin viewer. Rules 1, 2 | `curl -A "<script>alert(1)</script>" ...` then view the audit log as admin: shown as text. |
| 2FA failures counted as password failures | `LoginLockoutSubscriber::isTwoFactorStep()` | A wrong 2FA code is limited by its own limiter and doesn't lock the password counter. | — |

## Phase 4: Products, image upload, URL import

| Vulnerability | Where | Defense | How to test |
|---|---|---|---|
| IDOR on products | `src/Security/Voter/ProductVoter.php`, `src/Controller/Seller/ProductController.php` | UUIDs in URLs; `denyAccessUnlessGranted(EDIT/DELETE/TOGGLE)` on every action; owner compared by DB id. Hidden products → 404 for others. Rule 4 | As seller B, open seller A's `/seller/products/<uuid>/edit` → 403. POST delete → 403. |
| CSRF on product actions | `ProductController::delete()/toggle()`, `ProductFormType` | POST only + per-object CSRF token (`delete-product-<uuid>`). Rule 3 | `GET .../delete` → 405; POST with a forged `_token` → 403. |
| Mass assignment (owner, uuid, image name) | `src/Form/ProductFormType.php` | Explicit field list; seller set from session; extra fields rejected. Rule 9 | Add `product_form[seller]=1` → 422 "should not contain extra fields". |
| Malicious upload (webshell, polyglot, wrong type) | `src/Service/ImageStorage.php` | Real MIME via `finfo` (magic bytes), whitelist jpeg/png/webp, `getimagesize` must match, **GD re-encoding** strips any embedded payload/EXIF, random 128-bit name with extension from detected type (client filename ignored). Rule 7 | Upload `<?php ... ?>` renamed `x.jpg` → "text/x-php is not allowed". Upload a JPEG with `<?php` appended → accepted, but the stored file contains no `<?php` (`grep` in `var/uploads/products`). Note: Windows Defender may quarantine a real webshell test file before it is even sent. |
| Decompression bomb / huge file | `ImageStorage::validateAndStore()`, `ProductFormType` (`File maxSize`) | 2 MB max; max 8000 px per side and 20 Mpx, checked from the header before decoding. | Upload a 45-byte PNG declaring 50000×50000 → "Image dimensions are too large". |
| Uploaded file executed / path traversal | `ImageStorage` (`var/uploads/products`), `src/Controller/MediaController.php` | Stored outside `public/`; served only by a route whose regex is `[a-f0-9]{32}\.(jpg|png|webp)`; fixed `Content-Type`, `nosniff`, sandbox CSP. Rule 7 | `/media/product/..%2F..%2F.env` → 404; response headers of a real image show `X-Content-Type-Options: nosniff`. |
| SSRF via "import from URL" | `src/Service/SafeImageFetcher.php` | https only, port 443 only, no userinfo; hostname syntax check (blocks `2130706433`, `0x7f.1`, `localhost`); resolves A+AAAA and requires **every** IP to be public (blocks 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7, fe80::/10, IPv4-mapped, multicast...); connection **pinned** to the validated IP (anti DNS-rebinding); no redirects; 5 s timeout; streamed 2 MB cap; wrapped in `NoPrivateNetworkHttpClient`; result goes through `ImageStorage`. All refusals audit-logged (`ssrf.blocked`). Rule 8 | Import `https://169.254.169.254/latest/meta-data/`, `https://127.0.0.1.nip.io/`, `http://127.0.0.1/`, `https://example.com:22/` → refused; `audit_log` has `ssrf.blocked` rows. `https://google.com/` (301) → "HTTP 301" refused. |
| Stored XSS in product name/description | `templates/catalog/show.html.twig` | Plain text, auto-escaped, line breaks via CSS `white-space: pre-line` (no `raw`, no `nl2br`). Rule 2 | Description `<script>alert(1)</script>` is displayed as text. |

## Phase 5: Catalogue & search

| Vulnerability | Where | Defense | How to test |
|---|---|---|---|
| SQL injection in search / filters | `src/Repository/ProductRepository.php::search()`, `src/Dto/ProductSearchCriteria.php` | QueryBuilder + `setParameter()` for text, category, prices. LIKE wildcards `%` `_` escaped. Rule 1 | `/products?q=' OR 1=1 -- ` → 0 results, no SQL error. `/products?q=%25` → 0 results (not "everything"). |
| SQL injection through ORDER BY | `ProductSearchCriteria::SORTS` | ORDER BY can't be parameterised, so `sort` is mapped through a fixed whitelist; unknown values → `newest`. Rule 1 | `/products?sort=p.price;DROP TABLE user` → normal page sorted by newest. |
| Invalid / abusive input | `ProductSearchCriteria::fromRequest()` | Query ≤ 100 chars, category whitelist, price regex + range, page int 1..10000. Rule 15 | `/products?page=-5`, `?min=abc` → ignored, page 1. |
| Reflected XSS via search term | `templates/catalog/index.html.twig` | "Results for …" and the input `value` are auto-escaped. Rule 2 | `/products?q=<script>alert(1)</script>">` → shown as text, no alert. |
| Information disclosure (seller email, hidden products) | `User::getPublicName()`, `CatalogController::show()` | Public pages show a display name, never the email. Hidden products → 404 (not 403) for others. | Product page shows "Sold by …" without an email; a hidden product's URL → 404 when logged out. |

## Phase 6: Cart, checkout, orders

| Vulnerability | Where | Defense | How to test |
|---|---|---|---|
| Price tampering | `src/Entity/CartItem.php` (no price column), `src/Form/CheckoutFormType.php`, `src/Service/CheckoutService.php` | The cart stores no price; the checkout form has only payment fields; `CheckoutService` re-reads price and stock from the DB **after** locking the rows and snapshots `unitPrice` into `OrderItem`. Rule 10 | Burp: add `price=0.01` to the add-to-cart POST → ignored. Add `checkout_form[total]=0.01` → 422 "should not contain extra fields". The order total = DB price × qty. |
| Race condition on stock (overselling) | `CheckoutService::lockProducts()` | One DB transaction; `SELECT … FOR UPDATE` (PESSIMISTIC_WRITE) on the products, in id order (no deadlock), refreshed after the lock; stock checked and decremented on the locked row; any error rolls back everything. Rule 10 | Set a product's stock to 1, put it in two buyers' carts, submit both checkouts at the same time (two Burp Repeater tabs, "send group in parallel") → exactly one order, stock 0, the other buyer gets "Only 0 unit(s) left". |
| Invalid quantities | `CartController::readQuantity()`, `CartItem::setQuantity()` | Integer 1..99 only (`filter_var` + clamp in the entity), never more than stock. Rule 15 | POST `quantity=-5`, `1e9`, `abc` → 403. |
| Card data exposure | `src/Dto/PaymentDto.php`, `src/Entity/Order.php`, `CheckoutController` | DTO never persisted; only holder + last 4 stored; CVV is a `PasswordType` (never re-rendered); `eraseSensitiveData()` after use; `__debugInfo/__serialize` mask the number; `Cache-Control: no-store` on the checkout page; Luhn + format + expiry validation server side. Rules 11, 15 | Pay with `4242 4242 4242 4242`, then `mysqldump secure_marketplace \| grep 4242424242424242` → nothing. Bad Luhn → "not valid"; `01/20` → "expired". |
| IDOR on orders | `src/Security/Voter/OrderVoter.php`, `OrderController` | Order UUIDs; VIEW = buyer, a seller with items in it (sees only their own lines), or admin. Lists are scoped by query. Rule 4 | Buyer B opens buyer A's `/orders/<uuid>` → 403; another seller → 403. |
| Unauthorised / illogical status changes | `OrderVoter::ITEM_UPDATE_STATUS`, `OrderItem::advanceStatus()` | Only the item's seller; POST + per-item CSRF; strict state machine paid → shipped → delivered (no jumps, no going back). Rules 3, 4 | POST `status=delivered` on a paid item → refused; another seller → 403. |
| Cart IDOR | `CartItemRepository`, `CartController` | Cart lines are addressed by product UUID and always looked up with the logged-in user; no cart-item id in URLs. Rule 4 | There is no parameter that designates another user's cart. |
| CSRF on cart / checkout | `CartController::assertCsrf()`, `CheckoutFormType` | Per-action tokens (`cart-add-<uuid>`…), checkout form token. Rule 3 | POST `/cart/add/<uuid>` without `_token` → 403. |
