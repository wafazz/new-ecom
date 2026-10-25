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
5. Make these folders writable by the web server user (usually `www-data` on Ubuntu; check with `ps aux | grep php-fpm`). Nothing else needs to be writable:
   - `public/uploads/`: product photos and the shop and brand logos. If it is not writable, saving a product with photos fails.
   - `storage/logs/`: the app log. Errors are written here, so check it first when something breaks.
   - `storage/receipts/`: bank transfer receipts (private, never public).
   - `storage/backups/`: database backups from `/admin/backup` and `bin/backup.php`.
   - `storage/cache/`: visitor counts and the worker's lock file.
   - `storage/waybills/`: shipping labels.
   - `storage/`: the install wizard writes `installed.lock` here.

   Run this from the project folder, with your web user in place of `www-data` if it differs:

   ```
   sudo chown -R www-data:www-data public/uploads storage
   sudo chmod -R 775 public/uploads storage
   ```

   Do not make `app/`, `config/` or `.env` writable by the web user. Permissions can reset after a redeploy or a server move, so check them again then.
6. Cron, daily: `php bin/prune-carts.php` (removes stale guest carts and expired throttle/click rows). Also `php bin/retention.php` (deletes old email and WhatsApp logs, audit rows, daily visitor counts and app.log entries; see **Personal data** below).
7. Cron, hourly: `php bin/expire-unpaid.php` (cancels unpaid online-payment orders after `UNPAID_HOLD_HOURS`, default 24, and restocks them).
8. Cron, **every minute**: `php bin/worker.php` sends the queued emails and WhatsApp messages. Without it, nothing queued is sent. See **Background jobs** below.
8. NinjaVan: register `APP_URL/shipping/ninjavan/webhook` in the Ninja Dashboard for tracking updates, and ask NinjaVan support to enable the waybill API for your account. Billplz needs to reach `APP_URL/payments/billplz/callback`, so APP_URL must be a public https address (a local URL cannot receive payment confirmations). Try it in Sandbox mode first.
9. Backups: schedule `php bin/backup.php` daily and keep a copy off the server (see **Backups** below).
10. Rate limits (login, checkout, register, track) key on the connecting IP (`REMOTE_ADDR`). Behind a reverse proxy or CDN every visitor shares the proxy's IP, so limits would hit everyone at once: terminate TLS on the web server itself, or add trusted-proxy handling before going behind one.
11. Bank transfer receipts are uploaded files (JPG, PNG, WebP or PDF, up to 5 MB) kept in `storage/receipts/` (never public). Set PHP `upload_max_filesize` to at least 6M and `post_max_size` to at least 12M, and make `storage/receipts/` writable by the web user; back it up with the database. **nginx also limits uploads**, and its default (1 MB) rejects most phone photos with an error page. In the server block, set `client_max_body_size 12M;` and reload nginx. Product photos are up to 2 MB each, five per product, so the same 12M covers them.
12. Confirm: `/.env`, `/app/...`, `/storage/logs/app.log` return 403/404; `/health` returns OK.
13. Shipping: set both zones' rates at `/admin/shipping-rates` before taking orders. Checkout stays closed until every rate is filled in. Every product needs its weight and size (the product form requires them).

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

## Personal data
- **Announcements** (`/admin/email/announce`) add an unsubscribe link to every email, plus the one-click mail-client header. Anyone who unsubscribes is left out of later announcements (`email_unsubscribes`). Order and account emails are not affected. The link is signed with `APP_KEY`, so it works only for links this shop sent.
- **Retention** (`php bin/retention.php`, daily) removes email and WhatsApp logs after 180 days, audit rows after 365 days, daily visitor counts after 400 days, and app.log entries after 90 days. Change the periods with `--logs-days`, `--audit-days`, `--visitor-days` and `--app-log-days`.
- Visitors are stored as a hash of a browser cookie. The all-time visitor total is kept, because it holds no personal data.

## Discount codes
HQ creates codes at `/admin/vouchers`: a percent or a fixed RM amount, an optional minimum spend, an optional use limit and start and end dates. A buyer enters the code on checkout.
- A percent code takes that share of the items; a fixed code takes RM off the items. Shipping is never discounted, and a discount never takes more than the items cost the buyer. A 100% code can take the items to RM0, below the cost price, so only give such codes out deliberately.
- Checkout re-checks the code against the live cart and uses it up in the same transaction as the order, so a code cannot be used past its limit.
- Cancelling an order gives its use back. Used codes cannot be deleted, only switched off.
- Affiliate commission = rate % of (what the buyer paid − shipping), per product line, after any discount code. In other words, it is calculated on the items only: RM19 item + RM10 shipping (buyer pays RM29) at 10% gives RM1.90.
- Applying a code is limited to 20 tries per network every 10 minutes.

## Invoices
Every order that is not cancelled has a PDF invoice, numbered `INV-<year>-<order id>`. It is made when it is downloaded and not stored, so it always shows the current status and shop details.
- Admin and staff download it from the order page. Members download it from their orders. A guest uses the order's secret link, the same way they track the order.
- Set the company name, address, phone, email, registration number and footer note at `/admin/invoice`. The shop logo from Settings appears at the top.
- The invoice shows the payment method: bank transfer details when the order is unpaid, or PAID / UNPAID.
- **Not included:** SST (sales tax). The invoice shows no tax lines. If the business is registered for SST, add the tax lines before relying on these invoices for B2B buyers.

## Background jobs
Some sends are slow or depend on an outside service, so the shop does not make the customer or the admin wait for them. They are put in a queue (the `jobs` table) and sent by `bin/worker.php`:

| Work | When it is queued | What the worker does |
|---|---|---|
| Order placed: the "order created" email | right after checkout | sends it |
| Order placed or status changed: WhatsApp message | right after the change is saved | sends it |
| Announcements (`/admin/email/announce`) | when you click send | sends them in batches of 100 |
| Voucher codes (`/admin/vouchers`, Email members) | when you click send | sends them, and records each member who got the code |

**Set up the worker.** On the server, add this cron line (every minute):

```
* * * * * cd /path/to/ecommerce && php bin/worker.php >> storage/logs/worker.log 2>&1
```

Each run works for up to 50 seconds and then exits. A run that starts while another is still going does nothing, so runs never overlap. Migration `040_jobs.sql` creates the table, so run `php bin/migrate.php` first.

**Check and retry:**
- `php bin/worker.php --status` shows how many jobs are pending, running, done and failed.
- `php bin/worker.php --retry-failed` puts failed jobs back in the queue.

**What happens when something fails:**
- A failed job is tried again after 1, 2, 4 and 8 minutes: five tries in all. If the fifth fails, it is marked **failed** and waits for you to retry it.
- An announcement or voucher batch that fails for some people is queued again for just those people, five minutes later, up to three more rounds. Each email still shows in the email log with its result.
- A voucher code is never sent twice: the worker checks who already has the code each time it sends.
- WhatsApp messages the provider rejects are recorded in the WhatsApp log and are **not** retried. Only a crash or a timeout is retried.
- An order is never affected by a queue problem. If a job cannot be saved, the order is still placed, and the problem is written to the app log.

**Things to know:**
- **If the worker is not running, nothing queued is sent.** The jobs wait safely, and they go out once the worker runs again. Check `--status` if an announcement seems stuck.
- Messages can arrive up to a minute after the event, because the worker runs once a minute.
- A crash after an email is sent but before it is marked done can send it again. Batches are protected by Resend's idempotency key, which covers the same batch for 24 hours.
- Jobs hold customer addresses and message text. Finished jobs are removed after 7 days and failed ones after 30 days.
- The queue uses the database, so it needs no extra service such as Redis. It is designed for one shop, not for many workers running at once.

## Affiliate withdrawals
Members with approved commission ask HQ to pay them out, from their affiliate page.
- **Member:** enters any amount up to the balance, the bank, the account holder name and the account number. A RM1.00 transaction fee is taken from the amount, so asking for RM200 pays RM199. The amount must be more than RM1.00. One request can be waiting at a time.
- **While waiting,** the amount is held back from the member's balance, so it cannot be asked for twice.
- **HQ:** `/admin/withdrawals` lists requests, pending first, with the full bank details. HQ transfers the money by bank transfer, then clicks **Mark paid** and enters the bank reference. Or HQ clicks **Reject**, which gives the amount back to the member.
- **Oldest first:** a payment takes the oldest approved commission first. A commission can be paid in parts, so the rest stays owed until a later withdrawal covers it.
- **Account numbers** are stored encrypted with `APP_KEY`, like the other secrets. Losing `APP_KEY` loses them, so back it up with the database.
- The old "pay all approved" button on the affiliate page was removed, because it could pay out money a withdrawal already held.
- **Not yet:** paying through Billplz's payout API (Payment Orders). It needs the signature format confirmed against your sandbox first.

## Shipping
Postage depends on the buyer's state and the order's weight. Two zones:
- **Zone 1:** Peninsular Malaysia (every state except the three below).
- **Zone 2:** Sabah, Sarawak and Labuan.

Each zone has its own rates, set at `/admin/shipping-rates`:
- **First kilo rate** and **each next kilo** rate. The total weight of the cart is rounded up to whole kilos (1.2 kg is charged as 2 kg). The page still shows the real weight.
- **Non-COD orders** pay normal postage only.
- **COD orders** pay normal postage plus a COD surcharge. The surcharge is a percent of the items' sales, after any discount and before shipping. Sales at or below the zone's benchmark use the first percent; sales above it use the second.

Every product has a required weight and size (width, length and height in cm), set on the product form. Checkout refuses a cart with an item that has no weight. Postage is calculated on the server from the state and payment method the buyer picks, and the buyer sees it as they choose on the checkout page. Each order keeps its zone, billable kilos and COD surcharge.

The courier shipment form still takes its weight by hand. Connecting it to the order's billable weight is a planned follow-up.

## Money rules
All amounts are integer cents in code, `DECIMAL(10,2)` in the DB. Selling price = active discount else retail. Order lines snapshot price, cost, company base price and commission rate. Affiliate commission = rate % of the item line sales after any discount code's share, per line. Shipping is not part of it, since it is not in the line totals. The company base price is not used for commission. Commission is created when an order is completed, then approved, and paid out by withdrawal (see **Affiliate withdrawals**).

## Known limits
No email sending, password reset, online payment gateway, refunds, tax/invoices. See `Docs/Planning.md` section 13 for per-phase notes.
