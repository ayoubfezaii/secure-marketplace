# Secure Marketplace

University cybersecurity project: a multi-vendor marketplace where **security is the main grading criterion**.
Buyers browse, add to cart, check out (simulated payment) and review products; sellers manage their products
(image upload / import from URL) and orders; admins manage users and read the audit log.
Prices are in Tunisian dinars (TND, shown as `1 250,500 DT`, 3 decimals); payment by simulated card or cash on delivery.

Every feature is built to be secure by design: Doctrine parameterized queries, Twig auto-escaping, CSRF tokens,
Voters + UUIDs against IDOR, Argon2id + TOTP 2FA, rate limiting + account lockout, hardened file upload,
SSRF-safe URL import, libsodium-encrypted IBANs, strict security headers and an audit log.
The full list of defenses (with file/line references and how to test each one) is in [SECURITY.md](SECURITY.md).

**Stack:** Symfony 7.4 · PHP 8.2 · Twig · Doctrine ORM · MariaDB (XAMPP) · Windows

---

## Requirements

| Tool | Why | Get it |
|---|---|---|
| **XAMPP** (MariaDB + PHP 8.2) | Database server and PHP runtime | https://www.apachefriends.org |
| **PHP 8.2+** with `sodium`, `intl`, `gd`, `pdo_mysql` enabled | Run Symfony (check with `php -m`) | Included in XAMPP; enable extensions in `php.ini` |
| **Composer** | PHP dependencies | https://getcomposer.org |
| **Symfony CLI** | Local HTTPS web server | https://symfony.com/download |
| **Mailpit** | Catches e-mails (password reset, etc.) locally | https://mailpit.axllent.org |

> Make sure `php` in your terminal is the XAMPP one (`php -v` → 8.2.x, `where php`).

---

## Setup for a new teammate

1. **Clone the repository**
   ```bash
   git clone https://github.com/ayoubfezaii/secure-marketplace.git
   cd secure-marketplace
   ```

2. **Install dependencies** (also downloads the front-end vendor files into `assets/vendor/`)
   ```bash
   composer install
   ```

3. **Create your local config** — `.env.local` is git-ignored and must never be committed
   ```bash
   cp .env.local.example .env.local        # Windows cmd: copy .env.local.example .env.local
   ```
   Then edit `.env.local`:
   - `DATABASE_URL`: your MariaDB user/password (fresh XAMPP: `root` with an empty password → `mysql://root:@127.0.0.1:3306/...`)
   - `APP_SECRET`: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
   - `APP_ENCRYPTION_KEY`: `php -r "echo sodium_bin2hex(sodium_crypto_secretbox_keygen()), PHP_EOL;"`

4. **Start XAMPP MySQL/MariaDB and Mailpit**, then create the database
   ```bash
   php bin/console doctrine:database:create
   ```

5. **Run the migrations**
   ```bash
   php bin/console doctrine:migrations:migrate -n
   ```

6. **Load the demo data** (about 10 s, prints the demo accounts)
   ```bash
   php bin/console doctrine:fixtures:load -n
   ```

7. **Trust the local HTTPS certificate** (once per machine)
   ```bash
   symfony server:ca:install
   ```

8. **Start the server**
   ```bash
   symfony serve -d
   ```
   Open https://127.0.0.1:8000 — e-mails arrive in Mailpit at http://localhost:8025.
   Stop the server with `symfony server:stop`.

**Run the automated tests:** `php bin/phpunit`

---

## Demo accounts (dev only)

Password for every account: **`Password2026!`** — created by the fixtures, never use them in production.

| Role | Email | Notes |
|---|---|---|
| Admin | `admin@shop.test` | Only admin (new admins: `php bin/console app:create-admin <email>`) |
| Seller | `seller1@shop.test` … `seller3@shop.test` | Shops with products, orders and reviews |
| Seller | `seller4@shop.test` | **2FA enabled** — TOTP secret `JBSWY3DPEHPK3PXP` (add it to any authenticator app) |
| Buyer | `buyer1@shop.test` … `buyer7@shop.test` | Carts, orders, reviews, seller applications |
| Buyer | `buyer8@shop.test` | **Blocked** by the admin: login refused |

Full details per account: [TESTING.md](TESTING.md) §0.

---

## Documentation

- [SECURITY.md](SECURITY.md) — vulnerability → file/line → defense → how to test it
- [TESTING.md](TESTING.md) — end-to-end checklist for every role
- [CLAUDE.md](CLAUDE.md) — project rules (mandatory security rules every change must follow)

---

## Git workflow

```bash
# 1. Always get the latest code before you start
git pull origin main

# 2. Work, then check what you changed (make sure .env.local is NOT listed)
git status

# 3. Commit small, focused changes with a clear message
git add <files>
git commit -m "Short description of the change"

# 4. Pull again (in case someone pushed meanwhile), then push
git pull origin main
git push origin main
```

Rules: never commit `.env.local`, secrets or the `vendor/` / `var/` folders; after pulling, run
`composer install` and `php bin/console doctrine:migrations:migrate -n` if `composer.lock` or `migrations/` changed;
generate schema changes with `php bin/console make:migration`, never edit the database by hand.
