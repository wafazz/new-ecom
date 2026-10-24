# Lure Shop

Vanilla PHP 8 MVC e-commerce: public shop (guest + member checkout), admin / staff / member portals, product variants, orders, affiliate links and commissions. No framework, no Composer.

## Requirements
PHP 8.1+ (pdo_mysql, fileinfo), MySQL 8 / MariaDB 10.5+, Apache with `mod_rewrite` (or any server that routes everything to `public/index.php`).

## Local setup
```
cp .env.example .env          # then edit DB_*, APP_URL, APP_KEY
php bin/migrate.php --create-db
php bin/create-admin.php "Your Name" you@example.com     # prompts for the password
php -S 127.0.0.1:8099 -t public
```
Admin login: `/admin/login`. Staff: `/staff/login`. Members register at `/register`.

## Demo data
```
php bin/seed-demo.php                # 10 example products (6 single, 4 with variations), categories, brands, placeholder images, 12 NEW orders
php bin/seed-demo.php --orders=30    # how many sample orders in total (max 100)
php bin/seed-demo.php --no-images    # skip the generated placeholder pictures
php bin/seed-demo.php --remove       # delete everything the seeder made, and only that
```
Safe to re-run (nothing is duplicated) and it refuses to run when `APP_ENV=production`. The sample orders are all *pending* (New) and unpaid, placed through the real checkout, so prices, cost snapshots, stock and order history are genuine. Demo categories, brands and products have slugs starting with `demo-`; demo orders use `@demo.example.test` emails.

## Storefront
Public pages (`/`, `/products`, product, cart, checkout, order tracking, login/register) use `public/assets/css/shop.css` and `shop.js` (plus `catalog.js`, `product-page.js`, `cart-page.js`). The look follows the light/dark toggle in the header. Menus, footer and the "Sale" / "Popular" sections are built from real data, so they appear only when there is something to show; `php bin/seed-demo.php` fills a demo catalogue.

## Tests
```
php tests/run.php                                   # unit checks (no database needed)
DB_NAME=ecommerce_test php bin/migrate.php --create-db
DB_NAME=ecommerce_test php tests/run.php            # also runs checkout/affiliate DB tests
```
DB tests (checkout, affiliate, address book, wishlist) only run when `DB_NAME` ends in `_test`; they wipe that database's tables.

## Configuration (`.env`)
| Key | Notes |
|---|---|
| `APP_ENV` | `production` enables startup checks below |
| `APP_DEBUG` | must be `false` in production (errors go to `storage/logs/app.log`) |
| `APP_URL` | full URL; `https://` turns on secure cookies + HSTS |
| `APP_KEY` | random, 32+ chars; salts stored IP hashes. App refuses to start in production with the placeholder |
| `DB_*` | database connection |
| `BILLPLZ_BASE_URL` | optional; test/dev override of the Billplz API host (leave unset in production) |
| `NINJAVAN_BASE_URL` | optional; test/dev override of the NinjaVan API host (leave unset in production) |
| `AFFILIATE_COOKIE_DAYS` | referral attribution window (default 30) |
| `BANK_*` | starting values for the Bank transfer account; set the real ones on the admin **Payments** page (the example placeholders are ignored) |

Billplz and NinjaVan credentials are entered in the portal (Billplz / NinjaVan pages, admin or staff with the `payments.manage` permission), stored encrypted with a key derived from `APP_KEY`: back up `APP_KEY` with the database, because changing it means re-entering the Billplz and NinjaVan keys.

Stock is changed only on the **Stock Control** page (`/admin/stock`; staff need the `stock.manage` permission): add / remove / set with a reason, with a full history. Orders deduct stock and cancellations restore it automatically.

Bank names are chosen from a searchable list of Malaysian banks (Select2, self-hosted; a bank that is not listed can be typed in). At checkout, picking Bank transfer shows the shop's account and asks for the receipt, the bank paid from and the date and time; staff review each receipt on the order (approve = mark paid, or send it back with a reason).

Payment methods (admin **Payments** page, or staff with `payments.manage`): switch Cash on delivery, Bank transfer and Online banking (Billplz) on or off, and enter the bank account customers transfer to. Bank transfer is only offered once bank name, account number and account name are filled in, and at least one method must stay available.

Admin-editable (Settings page): guest checkout on/off, flat shipping fee, default affiliate commission %.

## Deploy checklist
1. Point the web root at `public/` (the root `.htaccess` is only a safety net).
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL` (https), `APP_KEY` from `php -r 'echo bin2hex(random_bytes(32));'`, DB credentials. Never commit `.env`.
3. `php bin/migrate.php` (safe to re-run; applies only new files). Back up the DB first.
4. `php bin/create-admin.php ...` for the first admin.
5. Make `storage/` (logs, cached waybills) and `public/uploads/` writable by the web user; nothing else needs to be.
6. Cron, daily: `php bin/prune-carts.php` (removes stale guest carts and expired throttle/click rows).
7. Cron, hourly: `php bin/expire-unpaid.php` (cancels unpaid online-payment orders after `UNPAID_HOLD_HOURS`, default 24, and restocks them).
8. NinjaVan: register `APP_URL/shipping/ninjavan/webhook` in the Ninja Dashboard for tracking updates, and ask NinjaVan support to enable the waybill API for your account. Billplz needs to reach `APP_URL/payments/billplz/callback`, so APP_URL must be a public https address (a local URL cannot receive payment confirmations). Try it in Sandbox mode first.
9. Backups: schedule `php bin/backup.php` daily and keep a copy off the server (see **Backups** below).
10. Rate limits (login, checkout, register, track) key on the connecting IP (`REMOTE_ADDR`). Behind a reverse proxy or CDN every visitor shares the proxy's IP, so limits would hit everyone at once: terminate TLS on the web server itself, or add trusted-proxy handling before going behind one.
11. Bank transfer receipts are uploaded files (JPG, PNG, WebP or PDF, up to 5 MB) kept in `storage/receipts/` (never public). Set PHP `upload_max_filesize` to at least 6M and `post_max_size` to at least 8M, and make `storage/receipts/` writable by the web user; back it up with the database.
12. Confirm: `/.env`, `/app/...`, `/storage/logs/app.log` return 403/404; `/health` returns OK.

## Backups
Admins can make a backup at `/admin/backup`, with or without the uploads and receipts zip. For a scheduled backup, add this to the server's crontab (daily at 02:30):

```
30 2 * * * cd /path/to/ecommerce && php bin/backup.php >> storage/logs/backup.log 2>&1
```

- `php bin/backup.php` saves a database dump plus a zip of `public/uploads/` and `storage/receipts/`. Add `--db-only` to skip the zip.
- It keeps the newest 14 database backups and deletes older ones; each zip is removed with its dump. Change the number with `--keep=N`.
- Backups are written to `storage/backups/`, which is never public and is not committed to git. The cron user must be able to write there.
- A backup on the same server does not protect against losing the server. Copy the newest files off the server regularly.
- Restore is not automated yet. To restore, import a `.sql` or `.sql.gz` backup into the database (gunzip first if needed) and unzip the files zip over the project folder.

## Money rules
All amounts are integer cents in code, `DECIMAL(10,2)` in the DB. Selling price = active discount else retail. Order lines snapshot price, cost, company base price and commission rate. Affiliate commission = rate % of (unit price - company base price) per line, created when an order is completed, then approved and paid out by an admin.

## Known limits
No email sending, password reset, online payment gateway, refunds, tax/invoices. See `Docs/Planning.md` section 13 for per-phase notes.
