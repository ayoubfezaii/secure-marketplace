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
