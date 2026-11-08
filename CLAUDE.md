# Secure Marketplace — Project Rules

University cybersecurity project. Symfony 7.4, PHP 8.2, Twig, Doctrine, MariaDB (XAMPP), Windows.
Security is the main grading criterion. Every feature must be secure by design and explainable to a professor.

## Domain
- Roles: ROLE_BUYER, ROLE_SELLER, ROLE_ADMIN (registration gives ROLE_BUYER or ROLE_SELLER, never ADMIN)
- Entities: User, Product, CartItem, Order, OrderItem, Review, AuditLog
- Features: register/login, 2FA (TOTP), password reset, product CRUD + image upload + import image from URL,
  search, cart, checkout (simulated payment), orders, reviews, profile (encrypted IBAN for sellers), admin panel, audit log

## Mandatory security rules
1. SQL injection: Doctrine QueryBuilder/DQL with setParameter() only. Never concatenate user input into queries.
2. XSS: Twig auto-escaping always on. Never use the `raw` filter on user data. Rich text goes through HTMLPurifier.
3. CSRF: every form and every state-changing action (POST/DELETE) has a CSRF token. No state change on GET.
4. Access control / IDOR: use Voters (ProductVoter, OrderVoter, ReviewVoter) for every object. Use UUIDs in public URLs.
5. Authentication: Argon2id ("auto") hashing, session fixation protection, 2FA with scheb/2fa-bundle (TOTP).
6. Brute force: symfony/rate-limiter on login and password reset + account lockout (failedAttempts, lockedUntil).
7. File upload: whitelist jpg/png/webp, check real MIME type, max 2MB, random filename, store outside public/, serve through a controller.
8. SSRF: "import image from URL" only allows https, resolves DNS and blocks private/loopback/link-local IPs (127.0.0.0/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1), no redirects, timeout.
9. Mass assignment: use DTOs / explicit form fields. Never let a request set roles, price of an order, owner, or isVerified.
10. Price tampering: checkout always reads price and stock from the database, never from the request. Use DB transactions.
11. Sensitive data: secrets in .env.local / Symfony secrets, never committed. Seller IBAN encrypted with libsodium.
12. Open redirect: only allow internal paths for _target_path and redirects.
13. Security headers via nelmio/security-bundle: CSP, X-Frame-Options DENY, frame-ancestors 'none', HSTS, nosniff, Referrer-Policy.
14. Logging: AuditLog entity + Monolog for login success/failure, lockouts, role changes, orders, admin actions.
15. Validation: Symfony Validator constraints on every entity/DTO (length, format, ranges).
16. Errors: no stack traces in prod, custom error pages.

## Working style
- Work in small steps. After each step, list the files changed and which security rule each change applies.
- Add a comment `// SECURITY:` above every security-relevant line explaining what it prevents.
- Generate migrations with `php bin/console make:migration`, never edit the database by hand.
- Keep a file SECURITY.md updated: table of vulnerability -> file/line -> defense -> how to test it.
