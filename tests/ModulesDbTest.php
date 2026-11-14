<?php
// Superadmin role and module switches. The first part needs no database; the second runs only on `*_test` databases.
use App\Core\Auth;
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\User;
use App\Services\FlashSaleService as F;
use App\Services\Modules;
use App\Services\StockService;

check('superadmin lands on the admin portal', Auth::HOME['superadmin'] === '/admin');
check('superadmin signs in on its own page', Auth::LOGIN['superadmin'] === '/superadmin/login');
check('admin and superadmin both count as admin roles', Auth::ADMIN_ROLES === ['admin', 'superadmin']);
check('plain admin is not given the superadmin login', Auth::LOGIN['admin'] === '/admin/login');
check('flash sales is a known module', isset(Modules::LIST['flash_sales']));
check('an unknown module is never on', Modules::enabled('no_such_module') === false);

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run the module DB tests)\n";
    return;
}
reset_test_db();

check('a superadmin account can be stored', User::create('superadmin', 'Root', 'root@example.test', 'long-password-1') > 0);
check('the role is saved as superadmin', (string) DB::query("SELECT role FROM users WHERE email = 'root@example.test'")->fetchColumn() === 'superadmin');

$tote = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Linen Tote', 'slug' => 'linen-tote', 'sku' => 'TOTE01', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '50.00', 'discount_price' => null, 'stock' => 20, 'status' => 'active']);
$now = time();
F::save(null, ['name' => 'Now', 'starts_at' => date('Y-m-d H:i:s', $now - 3600), 'ends_at' => date('Y-m-d H:i:s', $now + 3600)], [$tote => '20.00']);

check('modules are on by default', Modules::enabled('flash_sales') === true);
check('a running sale shows while the module is on', count(F::live()) === 1 && F::hasLive());
check('a running sale prices the product while the module is on', F::offersFor([$tote])[$tote]['percent'] === '20.00');

Modules::set('flash_sales', false);
check('switching the module off turns it off', Modules::enabled('flash_sales') === false);
check('switched off: no storefront sale blocks', F::live() === [] && F::hasLive() === false);
check('switched off: no flash offers for prices', F::offersFor([$tote]) === []);
$attached = F::attach([['id' => $tote]]);
check('switched off: the product is not priced as a flash sale', $attached[0]['flash_percent'] === null);
check('switched off: the sale itself is kept, not deleted', (int) DB::query('SELECT COUNT(*) FROM flash_sales')->fetchColumn() === 1);

Modules::set('flash_sales', true);
check('switching it back on restores the sale', count(F::live()) === 1 && F::hasLive());

// Discount coupons: the same switch stops codes at checkout, including ones kept in a shopper's session.
DB::query("INSERT INTO vouchers (code, type, value, min_subtotal, active) VALUES ('SAVE10', 'percent', '10.00', '0.00', 1)");
check('coupons are on by default', \App\Services\VoucherService::quote('SAVE10', 10000)['ok'] === true);
Modules::set('vouchers', false);
$off = \App\Services\VoucherService::quote('SAVE10', 10000);
check('switched off: a valid code is refused', $off['ok'] === false && $off['discount'] === 0);
Modules::set('vouchers', true);
check('switched back on: the code works again', \App\Services\VoucherService::quote('SAVE10', 10000)['ok'] === true);

// Stock movement: switching the log page off must not stop stock changes being recorded.
$before = (int) DB::query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ?', [$tote])->fetchColumn();
Modules::set('stock_movements', false);
StockService::delta((int) $tote, null, -2, 'manual', null, null, 'module test');
$after = (int) DB::query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ?', [$tote])->fetchColumn();
check('switched off: a stock change is still recorded', $after === $before + 1);
check('switched off: the stock level still changes', (int) DB::query('SELECT stock FROM products WHERE id = ?', [$tote])->fetchColumn() === 18);
Modules::set('stock_movements', true);

// Back in stock: a sign-up on a sold-out product is kept, and refused when the item is already in stock.
DB::query('DELETE FROM stock_alerts');
$soldOut = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Sold Out Mug', 'slug' => 'sold-out-mug', 'sku' => 'MUG900', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '20.00', 'discount_price' => null, 'stock' => 0, 'status' => 'active']);
check('back in stock: a sign-up on a sold-out product is saved', \App\Services\StockAlertService::store($soldOut, 0, 'waiter@example.test', null) === 'saved');
check('back in stock: signing up twice is harmless', \App\Services\StockAlertService::store($soldOut, 0, 'waiter@example.test', null) === 'saved');
check('back in stock: a bad address is refused', \App\Services\StockAlertService::store($soldOut, 0, 'nope', null) === 'invalid');
check('back in stock: a waiting alert is recorded', \App\Services\StockAlertService::waiting($soldOut, 0) === true);
\App\Services\StockService::delta($soldOut, null, 4, 'manual', null, null, 'restock test');
check('back in stock: a restock queues the alert (waiting until the worker sends it)', \App\Services\StockAlertService::waiting($soldOut, 0) === true);
check('back in stock: an item already in stock is not put on the list', \App\Services\StockAlertService::store($soldOut, 0, 'other@example.test', null) === 'in_stock');

// Newsletter: a sign-up only counts once its confirmation link is used, and the link works once.
DB::query('DELETE FROM newsletter_subscribers');
$nlToken = bin2hex(random_bytes(32));
DB::query("INSERT INTO newsletter_subscribers (email, status, token_hash, token_expires) VALUES ('fan@example.test', 'pending', ?, NOW() + INTERVAL 1 DAY)", [hash('sha256', $nlToken)]);
check('newsletter: a pending sign-up is not active yet', (int) DB::query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active'")->fetchColumn() === 0);
check('newsletter: the confirmation link confirms the address', \App\Services\NewsletterService::confirm($nlToken) === 'fan@example.test');
check('newsletter: the same link cannot be used twice', \App\Services\NewsletterService::confirm($nlToken) === null);
check('newsletter: a bad link is refused', \App\Services\NewsletterService::confirm(str_repeat('a', 64)) === null);
check('newsletter: a bad address is refused before anything is sent', \App\Services\NewsletterService::subscribe('not-an-email') === 'invalid');

// Digital products: hidden from the shop and not sold while off. Existing downloads are not touched.
$digId = Product::insert(['weight_kg' => null, 'width_cm' => null, 'length_cm' => null, 'height_cm' => null,
    'name' => 'Ebook Guide', 'slug' => 'ebook-guide', 'sku' => 'EBK001', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '30.00', 'discount_price' => null, 'stock' => 0, 'status' => 'active',
    'product_type' => 'digital', 'digital_delivery' => 'keys']);
check('digital products are on by default: the product page loads', Product::publicBySlug('ebook-guide') !== null);
Modules::set('digital_products', false);
check('switched off: the digital product page is not found', Product::publicBySlug('ebook-guide') === null);
check('switched off: digital products are not in the shop listing',
    array_filter(Product::publicList('', null, 'newest', 1, 50)['rows'], fn($r) => $r['slug'] === 'ebook-guide') === []);
check('switched off: a digital product cannot be added to a cart', \App\Services\CartService::add($digId, null, 1) === 'Digital products are not available right now.');
Modules::set('digital_products', true);

// Affiliates: a referral code is only recognised while the module is on (clicks, cookies and checkout attribution use it).
$affId = User::create('member', 'Ana Referrer', 'ana@example.test', 'long-password-1');
$refCode = \App\Services\AffiliateService::ensureCode($affId);
check('affiliates are on by default: the code is recognised', \App\Services\AffiliateService::findByCode($refCode) !== null);
Modules::set('affiliates', false);
check('switched off: the referral code is ignored', \App\Services\AffiliateService::findByCode($refCode) === null);
Modules::set('affiliates', true);

$all = Modules::all();
check('the module list has every registered module', count($all) === count(Modules::LIST));
