# TESTING.md — End-to-end checklist

Tick each box while testing. Security attack tests (Burp, curl) are in `SECURITY.md`; this file checks that every
feature works for every role.

## 0. Preparation

- [ ] XAMPP MySQL/MariaDB running, Mailpit running (UI http://localhost:8025)
- [ ] `composer install`
- [ ] `.env.local` contains `DATABASE_URL`, `MAILER_DSN=smtp://localhost:1025`, `APP_SECRET`, `APP_ENCRYPTION_KEY` (see README section in the final summary / `SECURITY.md` §3)
- [ ] `php bin/console doctrine:migrations:migrate -n`
- [ ] `php bin/console doctrine:fixtures:load -n` → prints the demo accounts
- [ ] `symfony server:start -d --port=8001` → open https://127.0.0.1:8001
- [ ] All demo passwords: `Password2026!`

Tip: use a normal window + a private window to be logged in as two users at once.

---

## 1. Visitor (not logged in)

- [ ] Home page shows the 8 latest products with images
- [ ] **Products** page: 29 visible products (one is hidden), 12 per page, pagination works
- [ ] Search `headphones` → "Results for “headphones”"
- [ ] Category filter (Books), min/max price (10 → 40), each sort option changes the order
- [ ] Product page: image, seller name (not an email), rating stars, reviews, "Log in as a buyer to purchase"
- [ ] `/cart`, `/buyer`, `/seller`, `/admin`, `/account/profile` → redirected to the login page
- [ ] Login with a wrong password → "Invalid credentials."; with an unknown email → same message
- [ ] Unknown URL (e.g. `/nothing`) → custom 404 page

## 2. Registration & password reset

- [ ] Register as **buyer** with `abc` as password → policy errors; with `password1234` → "leaked in a data breach" (needs internet)
- [ ] Register `newbuyer@shop.test` with a strong password → redirected to login with a success message → log in → lands on `/buyer`
- [ ] Register with an existing email → "already registered"
- [ ] Login page → **Forgot password?** → enter `buyer2@shop.test` → "Check your email" page
- [ ] Same with `nobody@shop.test` → exactly the same page
- [ ] Mailpit shows ONE email (for buyer2); click the link → choose a new password → log in with it
- [ ] Click the same link again → "invalid or has expired"

## 3. Buyer (`buyer1@shop.test`)

### Dashboard (`/buyer`)
- [ ] 4 stat cards: orders, total spent, items in cart (3), reviews written
- [ ] "Enable 2FA" banner
- [ ] Recent orders table with status badges; click an order → order detail
- [ ] "Products to review" list → **Review** button goes to the product's review form
- [ ] Sidebar: Overview, My cart, My orders, Browse products, Profile, Security settings

### Cart & checkout
- [ ] Cart shows the 2 fixture items with DB prices and a total
- [ ] Change a quantity → Update → total recalculated; Remove a line
- [ ] On a product page: Add to cart (quantity 2) → flash message, cart updated
- [ ] Add more than the stock → quantity capped with a warning
- [ ] Out-of-stock product ("Wi-Fi 6 Router") has no Add to cart button
- [ ] Checkout: summary matches the cart; pay with name `Alice Martin`, card `4242 4242 4242 4242`, expiry `12/30`, CVV `123`
- [ ] Invalid card `4242 4242 4242 4241` → "not valid"; expiry `01/20` → "expired"; after an error the CVV field is empty
- [ ] Successful payment → order page "Paid by card •••• 4242", cart is empty, product stock decreased

### Orders & reviews
- [ ] **My orders** lists all orders, newest first
- [ ] Review a purchased product (rating + comment) → appears on the product page with your display name
- [ ] The review form is gone for that product (one review per product)
- [ ] A product you never bought shows "Only buyers who purchased this product can review it"
- [ ] Delete your own review → removed

### Profile & security
- [ ] **Profile**: change display name and phone → saved; no IBAN field for buyers
- [ ] **Change password**: wrong current password → error; correct → logged out, log in with the new one
- [ ] **Security settings** → Set up 2FA → scan the QR code (Google Authenticator / Aegis / 1Password) → enter the code → "Enabled"
- [ ] Log out / log in → asked for the 6-digit code → correct code → dashboard
- [ ] Wrong code 5 times → "Too many invalid codes"
- [ ] Disable 2FA with password + current code → "Disabled"

## 4. Seller (`seller1@shop.test`)

### Dashboard (`/seller`)
- [ ] 5 stat cards: active products, units sold, revenue, pending shipments, average rating
- [ ] Sales chart (last 30 days, bars = revenue, line = units) renders
- [ ] Low stock alert lists products with stock < 5 (4K Webcam, Hardware Security Key, Wi-Fi 6 Router)
- [ ] Recent orders table with "Mark shipped" / "Mark delivered" buttons
- [ ] My products table with Edit / Hide / Delete
- [ ] Latest reviews on my products

### Products
- [ ] **New product**: name, description, price, stock, category, upload a JPG/PNG/WebP < 2 MB → created, image visible
- [ ] Upload a `.gif` or a text file renamed `.jpg` → error message, product not created
- [ ] Edit a product → **import image from URL** with a public `https://…` image → image replaced
- [ ] Import `http://example.com/a.png` → "Only https:// URLs are allowed"; `https://127.0.0.1/x.png` → "This URL is not allowed"
- [ ] Hide a product → disappears from the catalogue, still visible to you with a "hidden" banner; Show it again
- [ ] Delete a product (confirmation prompt) → removed; existing orders still show its name

### Orders
- [ ] **Orders** lists only your items; open an order → only your lines, no card information
- [ ] Mark an item **shipped**, then **delivered** → buyer's order status follows
- [ ] Profile: add IBAN `FR76 3000 6000 0112 3456 7890 189` → shows `•••• •••• •••• 0189`; invalid IBAN → error; "Remove my IBAN" works
- [ ] As seller2, open seller1's product edit URL → 403

## 5. Admin (`admin@shop.test`)

### Dashboard (`/admin`)
- [ ] Warning banner "Your admin account has no 2FA"
- [ ] Stat cards: buyers, sellers, products, orders, revenue, admins, locked accounts, failed logins 24 h, security events 24 h
- [ ] Latest security events table

### Users
- [ ] Search `buyer`, filter by role, pagination
- [ ] Change a buyer to seller → their open session is logged out; they now land on `/seller`
- [ ] Block a buyer → their open session ends on the next click; they can't log in ("This account has been blocked…"); Unlock → they can log in
- [ ] Your own row and other admins show "Managed from the console" (no actions)

### Moderation
- [ ] **Products**: deactivate a product → hidden from the catalogue; reactivate
- [ ] **Orders**: filter by status (paid / shipped / delivered); open any order (full details visible)
- [ ] **Reviews**: delete a review → gone from the product page; logged as moderation

### Logs
- [ ] **Audit log**: filter by action, user email, date range; pagination; User-Agent column shown
- [ ] **Security panel**: failed logins, lockouts & rate limiting, blocked SSRF/uploads, access denied (from the 403 tests above)

### Admin creation
- [ ] `php bin/console app:create-admin admin2@shop.test` in **cmd or PowerShell** → hidden password prompt (twice) → success
- [ ] Same command with `-n` → "must be run interactively"; with an existing email → refused

## 6. Hardening checks

- [ ] `curl -skI https://127.0.0.1:8001/` shows CSP, HSTS, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, no X-Powered-By
- [ ] DevTools console on any page: no CSP violation errors (Bootstrap menu, delete confirmations and the seller chart all work)
- [ ] Session cookie flags: Secure, HttpOnly, SameSite=Lax
- [ ] `/_error/403`, `/_error/404`, `/_error/500` (dev preview of prod pages) → generic messages, no stack trace
- [ ] `composer audit`, `php bin/console importmap:audit`, `vendor/bin/phpstan analyse` → all clean
- [ ] `php bin/console lint:twig templates`, `lint:yaml config`, `lint:container` → OK
