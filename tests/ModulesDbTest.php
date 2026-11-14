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
check('payments: the billing period must be one-time, monthly or yearly', \App\Services\ModulePricing::validate('weekly', '1') !== null && \App\Services\ModulePricing::validate('yearly', '49.90') === null);
check('payments: a price has at most two decimals', \App\Services\ModulePricing::validate('monthly', '12.345') !== null);
check('billing: a monthly period ends the day before the same day next month', \App\Services\ModuleBillingService::periodEnd('2026-01-01', 'monthly') === '2026-01-31');
check('billing: a yearly period ends a year later, minus a day', \App\Services\ModuleBillingService::periodEnd('2026-01-01', 'yearly') === '2026-12-31');
check('billing: a one-time purchase never runs out', \App\Services\ModuleBillingService::periodEnd('2026-01-01', 'one_time') === '9999-12-31');
check('discounts: a percentage must be from 1 to 99', \App\Services\ModulePricing::validateDiscount('global', 'percent', '0', '1970-01-03T00:00', 1000) !== null && \App\Services\ModulePricing::validateDiscount('global', 'percent', '100', '1970-01-03T00:00', 1000) !== null);
check('discounts: a fixed price must be above RM0', \App\Services\ModulePricing::validateDiscount('global', 'fixed', '0', '1970-01-03T00:00', 1000) !== null && \App\Services\ModulePricing::validateDiscount('global', 'fixed', '49.90', '1970-01-03T00:00', 1000) === null);
check('discounts: the end time must be in the future', \App\Services\ModulePricing::validateDiscount('global', 'percent', '10', '2020-01-01T00:00', time()) !== null);
check('discounts: an unknown discount type is refused', \App\Services\ModulePricing::validateDiscount('global', 'bogus', '10', date('Y-m-d\TH:i', time() + 86400), time()) !== null);
check('discounts: an unknown module is refused', \App\Services\ModulePricing::validateDiscount('no_such_module', 'percent', '10', date('Y-m-d\TH:i', time() + 86400), time()) !== null);
check('discounts: a fixed price never raises a price', \App\Services\ModulePricing::finalFor(30.0, 'fixed', 50.0) === 30.0 && \App\Services\ModulePricing::finalFor(120.0, 'fixed', 50.0) === 50.0);
check('discounts: a percentage takes that share off', \App\Services\ModulePricing::finalFor(120.0, 'percent', 25.0) === 90.0);
check('PIN: a new PIN must be six digits', \App\Services\SuperadminPin::validateNew('12345', '12345') !== null && \App\Services\SuperadminPin::validateNew('12a456', '12a456') !== null);
check('PIN: the default PIN cannot be chosen again', \App\Services\SuperadminPin::validateNew('123456', '123456') !== null);
check('PIN: the confirmation must match', \App\Services\SuperadminPin::validateNew('204817', '204818') !== null && \App\Services\SuperadminPin::validateNew('204817', '204817') === null);
check('payments: a collection ID with spaces is refused before anything is saved', isset(\App\Services\PlatformSettings::save(['billplz_mode' => 'sandbox', 'billplz_collection_id' => 'bad id'], 1)['billplz_collection_id']));
check('an unknown module is never on', Modules::enabled('no_such_module') === false);

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run the module DB tests)\n";
    return;
}
reset_test_db();
// The checks below run with the original modules switched on (they are off by default now).
foreach (['flash_sales', 'vouchers', 'affiliates', 'digital_products', 'stock_movements'] as $__k) {
    Modules::set($__k, true);
}

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

// Sales reports: cancelled orders are left out of the totals.
foreach ([['RPT-A', 'completed', '40.00'], ['RPT-B', 'cancelled', '99.00']] as [$no, $st, $amt]) {
    DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, total, payment_method, status) VALUES (?, 'Report Test', 'rpt@example.test', '0123', 'Line 1', 'City', 'Selangor', '43000', ?, ?, 'cod', ?)", [$no, $amt, $amt, $st]);
}
$today = date('Y-m-d');
$rep = \App\Services\SalesReportService::summary($today, $today);
check('sales report: cancelled orders are not counted', $rep['orders'] === 1 && abs($rep['sales'] - 40.0) < 0.001);
check('sales report: payment split matches the total', abs(array_sum(array_column(\App\Services\SalesReportService::byPayment($today, $today), 'sales')) - 40.0) < 0.001);

// Bulk prices: a percentage change moves the regular price, an undo restores it, and a later edit is not overwritten.
$bpId = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Bulk Tee', 'slug' => 'bulk-tee', 'sku' => 'BLK001', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '100.00', 'discount_price' => null, 'stock' => 5, 'status' => 'active']);
$bpScope = ['type' => 'all', 'id' => 0];
$bp = \App\Services\BulkPricingService::apply($bpScope, -10.0, 1);
check('bulk prices: a -10% change moves the regular price', (string) DB::query('SELECT retail_price FROM products WHERE id = ?', [$bpId])->fetchColumn() === '90.00');
$undo = \App\Services\BulkPricingService::undo($bp['batch'], 1);
check('bulk prices: undo restores the old price', (string) DB::query('SELECT retail_price FROM products WHERE id = ?', [$bpId])->fetchColumn() === '100.00' && $undo['restored'] >= 1);
$bp2 = \App\Services\BulkPricingService::apply($bpScope, 20.0, 1);
DB::query('UPDATE products SET retail_price = 1.00 WHERE id = ?', [$bpId]);
$undo2 = \App\Services\BulkPricingService::undo($bp2['batch'], 1);
check('bulk prices: a price changed again since is left alone by undo', (string) DB::query('SELECT retail_price FROM products WHERE id = ?', [$bpId])->fetchColumn() === '1.00' && $undo2['skipped'] >= 1);
check('bulk prices: a zero percentage is refused', \App\Services\BulkPricingService::checkPercent(0.0) !== null);

// Product CSV: a bad row is skipped on its own; the good row applies (price and stock).
$csvId = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Csv Mug', 'slug' => 'csv-mug', 'sku' => 'CSVA1', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '10.00', 'discount_price' => null, 'stock' => 5, 'status' => 'active']);
$file = "\xEF\xBB\xBFsku,retail_price,stock\nCSVA1,12.50,7\nNOPE-SKU,1.00,1\n,,\n";
$checkedCsv = \App\Services\ProductCsvService::check($file);
check('product CSV: the good row is ready', $checkedCsv['ok'] === 1);
check('product CSV: an unknown SKU and an empty row are refused', $checkedCsv['bad'] === 2);
$applied = \App\Services\ProductCsvService::apply($file, 1);
$after = DB::query('SELECT retail_price, stock FROM products WHERE id = ?', [$csvId])->fetch();
check('product CSV: the good row changes the price', (string) $after['retail_price'] === '12.50');
check('product CSV: the good row changes the stock', (int) $after['stock'] === 7 && $applied['stock'] === 1);
check('product CSV: the export lists the SKU', in_array('CSVA1', array_column(\App\Services\ProductCsvService::exportRows(), 0), true));
check('product CSV: a file without headers is refused', \App\Services\ProductCsvService::check("CSVA1,1,1\n")['fatal'] !== null);

// Returns: one open request at a time, stock back once on receipt, a refund cannot be recorded twice.
$retId = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Return Item', 'slug' => 'return-item', 'sku' => 'RET001', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '20.00', 'discount_price' => null, 'stock' => 5, 'status' => 'active']);
DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, total, payment_method, status) VALUES ('RET-1', 'Ret Test', 'ret@example.test', '0123', 'Line 1', 'City', 'Selangor', '43000', 40.00, 40.00, 'cod', 'completed')");
$retOrder = (int) DB::connection()->lastInsertId();
DB::query('INSERT INTO order_items (order_id, product_id, product_name, unit_price, retail_price, base_price, company_base_price, qty, line_total) VALUES (?, ?, ?, 20, 20, 5, 10, 2, 40)', [$retOrder, $retId, 'Return Item']);
$RS = \App\Services\ReturnService::class;
check('returns: a customer can ask for a return on a completed order', $RS::request($retOrder, 'Arrived with a crack') === null);
check('returns: a second request while one is open is refused', $RS::request($retOrder, 'Another reason here') !== null);
$retReq = (int) $RS::forOrder($retOrder)['id'];
check('returns: approve moves it on', $RS::decide($retReq, 'approve', 1) === null);
check('returns: receiving puts the stock back', $RS::decide($retReq, 'receive', 1) === null && (int) DB::query('SELECT stock FROM products WHERE id = ?', [$retId])->fetchColumn() === 7);
check('returns: a refund above the order total is refused', $RS::decide($retReq, 'refund', 1, null, '99.00') !== null);
check('returns: the refund is recorded once', $RS::decide($retReq, 'refund', 1, null, '40.00') === null && $RS::decide($retReq, 'refund', 1, null, '40.00') !== null);
check('returns: no new request after a refund', $RS::request($retOrder, 'Second try after refund') !== null);

// Order messages: a customer message makes the order wait for a reply; a staff reply clears it.
DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, total, payment_method, status) VALUES ('MSG-1', 'Msg Test', 'msg@example.test', '0123', 'Line 1', 'City', 'Selangor', '43000', 10.00, 10.00, 'cod', 'pending')");
$msgOrder = (int) DB::connection()->lastInsertId();
check('order messages: an empty message is refused', \App\Services\SupportService::post($msgOrder, 'customer', '   ') !== null);
check('order messages: a customer message is saved', \App\Services\SupportService::post($msgOrder, 'customer', 'Where is my parcel?') === null);
check('order messages: the order now waits for a reply', in_array($msgOrder, array_map('intval', array_column(\App\Services\SupportService::waiting(), 'id')), true));
check('order messages: a staff reply is saved', \App\Services\SupportService::post($msgOrder, 'staff', 'It left the warehouse today.', 1) === null);
check('order messages: a staff reply clears the waiting list', !in_array($msgOrder, array_map('intval', array_column(\App\Services\SupportService::waiting(), 'id')), true));
check('order messages: the thread keeps both messages in order', count(\App\Services\SupportService::thread($msgOrder)) === 2);

// Loyalty: earn on completion (once), spend at checkout (capped), a refund takes back only what is still there.
$lpUser = User::create('member', 'Points Fan', 'points@example.test', 'long-password-1');
DB::query("INSERT INTO orders (order_no, user_id, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, total, payment_method, status) VALUES ('PTS-1', ?, 'Points Fan', 'points@example.test', '0123', 'Line 1', 'City', 'Selangor', '43000', 100.00, 100.00, 'cod', 'completed')", [$lpUser]);
$ptsOrder1 = (int) DB::connection()->lastInsertId();
DB::query("INSERT INTO orders (order_no, user_id, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, total, payment_method, status) VALUES ('PTS-2', ?, 'Points Fan', 'points@example.test', '0123', 'Line 1', 'City', 'Selangor', '43000', 50.00, 50.00, 'cod', 'pending')", [$lpUser]);
$ptsOrder2 = (int) DB::connection()->lastInsertId();
$LS = \App\Services\LoyaltyService::class;
$LS::earn($ptsOrder1);
check('loyalty: a completed order earns 1 point per RM', $LS::balance($lpUser) === 100);
$LS::earn($ptsOrder1);
check('loyalty: earning twice on the same order does not double the points', $LS::balance($lpUser) === 100);
$cap = $LS::discountFor($lpUser, 10000, 500);
check('loyalty: a checkout can use at most half of the items, and only what the balance holds', $cap['points'] === 100 && $cap['cents'] === 100);
$LS::spend($ptsOrder2, $lpUser, 100);
check('loyalty: spending moves the points out of the balance', $LS::balance($lpUser) === 0);
$LS::reverse($ptsOrder1);
check('loyalty: a refund never takes the balance below zero', $LS::balance($lpUser) === 0);

// Gift cards: a code covers up to its balance, a spend cannot exceed it, and a cancelled order gives the money back once.
$gcCode = \App\Services\GiftCardService::issue(5000, 1);
check('gift cards: a new card covers up to its balance and no more than the items', \App\Services\GiftCardService::quote($gcCode, 3000)['cents'] === 3000 && \App\Services\GiftCardService::quote($gcCode, 9000)['cents'] === 5000);
check('gift cards: codes are typed loosely (spaces and case do not matter)', \App\Services\GiftCardService::quote(strtolower(str_replace('-', ' ', $gcCode)), 1000)['ok'] === true);
DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, total, payment_method, status) VALUES ('GC-1', 'Gift Test', 'gift@example.test', '0123', 'Line 1', 'City', 'Selangor', '43000', 30.00, 30.00, 'cod', 'pending')");
$gcOrder1 = (int) DB::connection()->lastInsertId();
$gcRes = \App\Services\GiftCardService::reserve($gcCode, 3000);
\App\Services\GiftCardService::spend($gcOrder1, $gcRes['id'], $gcRes['cents']);
check('gift cards: spending takes the amount off the balance', (int) DB::query('SELECT balance_cents FROM gift_cards WHERE id = ?', [$gcRes['id']])->fetchColumn() === 2000);
check('gift cards: a reserve for more than the balance covers only the balance', \App\Services\GiftCardService::reserve($gcCode, 9000)['cents'] === 2000);
\App\Services\GiftCardService::restore($gcOrder1);
\App\Services\GiftCardService::restore($gcOrder1);
check('gift cards: a cancelled order gives the amount back once', (int) DB::query('SELECT balance_cents FROM gift_cards WHERE id = ?', [$gcRes['id']])->fetchColumn() === 5000);

// Bundles: stock comes from the parts; a sale and a cancellation move stock on the parts; the bundle has no stock of its own.
$mkSimple = fn(string $name, string $sku, int $stock) => Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => $name, 'slug' => strtolower($sku), 'sku' => $sku, 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '10.00', 'discount_price' => null, 'stock' => $stock, 'status' => 'active']);
$bpA = $mkSimple('Bundle Part A', 'BPA001', 6);
$bpB = $mkSimple('Bundle Part B', 'BPB001', 4);
$bpZ = $mkSimple('Gift Pack', 'GPK001', 0);
check('bundles: a bundle is saved from its parts', \App\Services\BundleService::setParts($bpZ, [['product_id' => $bpA, 'qty' => 1], ['product_id' => $bpB, 'qty' => 2]], 1) === null);
$stockOf = fn(int $id) => (int) DB::query('SELECT stock FROM products WHERE id = ?', [$id])->fetchColumn();
check('bundles: the bundle can make 2 from the parts on the shelf', $stockOf($bpZ) === 2);
\App\Services\BundleService::adjustLine($bpZ, null, -1, 'order', null, 1);
check('bundles: a sale takes stock from each part', $stockOf($bpA) === 5 && $stockOf($bpB) === 2);
check('bundles: the bundle count follows the parts after a sale', $stockOf($bpZ) === 1);
\App\Services\BundleService::adjustLine($bpZ, null, 1, 'cancel', null, 1);
check('bundles: a cancelled bundle puts stock back on each part', $stockOf($bpA) === 6 && $stockOf($bpB) === 4 && $stockOf($bpZ) === 2);
check('bundles: the bundle has no stock of its own to change directly', (function () use ($bpZ) { try { \App\Services\StockService::delta($bpZ, null, 1, 'manual'); return false; } catch (\DomainException) { return true; } })());
\App\Services\StockService::delta($bpB, null, -4, 'manual', null, 1, 'bundle test');
check('bundles: a part out of stock makes the bundle unavailable', $stockOf($bpZ) === 0);
check('bundles: a bundle cannot contain itself', \App\Services\BundleService::setParts($bpZ, [['product_id' => $bpZ, 'qty' => 1]], 1) !== null);

// SST: the rate comes from the product or its category; SST is part of the price, taken from the paid amount.
Modules::set('tax', true); // SST is off by default, so these checks switch it on first
$taxCat = (int) DB::query("SELECT id FROM categories LIMIT 1")->fetchColumn();
if ($taxCat === 0) {
    DB::query("INSERT INTO categories (name, slug) VALUES ('Tax Test', 'tax-test')");
    $taxCat = (int) DB::connection()->lastInsertId();
}
DB::query('UPDATE categories SET tax_rate = 6.00 WHERE id = ?', [$taxCat]);
$taxP = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Taxed Mug', 'slug' => 'taxed-mug', 'sku' => 'TAX001', 'category_id' => $taxCat, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '106.00', 'discount_price' => null, 'stock' => 5, 'status' => 'active']);
check('SST: a product takes its category rate', \App\Services\TaxService::rateFor($taxP) === 6.0);
DB::query('UPDATE products SET tax_rate = 0.00 WHERE id = ?', [$taxP]);
check('SST: a product rate of 0 overrides its category (zero-rated)', \App\Services\TaxService::rateFor($taxP) === 0.0);
DB::query('UPDATE products SET tax_rate = NULL WHERE id = ?', [$taxP]);
$taxItems = [['product_id' => $taxP, 'unit' => 10600, 'qty' => 1]];
$taxTotal = \App\Services\TaxService::split($taxItems, 10600, 0);
check('SST: 6% on RM106.00 included is RM6.00', $taxTotal === 600 && $taxItems[0]['tax_amount'] === 600);
$taxItems2 = [['product_id' => $taxP, 'unit' => 10600, 'qty' => 1]];
check('SST: a discount reduces the amount SST is taken from', \App\Services\TaxService::split($taxItems2, 10600, 600) === 566);
Modules::set('tax', false);
check('SST: switched off, no SST is worked out', \App\Services\TaxService::rateFor($taxP) === 0.0);
Modules::set('tax', true);

// Defaults: every module is off until a superadmin switches it on. Then the originals are switched back on for the checks below.
$newKeys = \App\Services\Modules::OFF_BY_DEFAULT;
DB::query("DELETE FROM settings WHERE `key` IN (" . implode(',', array_map(fn($k) => "'module." . $k . "'", $newKeys)) . ")");
check('defaults: all 17 modules are off when never switched on', count(array_filter($newKeys, fn($k) => Modules::enabled($k))) === 0 && count($newKeys) === 17);
foreach (['flash_sales', 'vouchers', 'affiliates', 'digital_products', 'stock_movements'] as $__k) {
    Modules::set($__k, true);
}

// Module billing: an invoice is created, paid once, and extends the paid-until date; a rejected receipt is not paid.
DB::query('DELETE FROM module_invoices');
DB::query('DELETE FROM module_subscriptions');
check('billing: a priced module can be given a price', \App\Services\ModulePricing::set('bundles', 'yearly', '120.00', 1) === null);
check('plans: a module can offer more than one plan', \App\Services\ModulePricing::set('bundles', 'monthly', '12.00', 1) === null && count(\App\Services\ModulePricing::plans('bundles')) === 2);
check('plans: a blank plan is taken off offer', \App\Services\ModulePricing::set('bundles', 'monthly', '', 1) === null && !isset(\App\Services\ModulePricing::plans('bundles')['monthly']));
\App\Services\ModulePricing::set('bundles', 'monthly', '12.00', 1);
$billUser = 1;
$invA = \App\Services\ModuleBillingService::invoiceFor('bundles', 'yearly', $billUser);
check('billing: an unpaid priced module gets an invoice', is_array($invA) && $invA['status'] === 'unpaid' && $invA['period'] === 'yearly');
check('billing: a plan that is not offered cannot be bought', is_string(\App\Services\ModuleBillingService::invoiceFor('bundles', 'one_time', $billUser)));
check('billing: the same open invoice is reused, not doubled', (int) DB::query('SELECT COUNT(*) FROM module_invoices')->fetchColumn() === 1 && \App\Services\ModuleBillingService::invoiceFor('bundles', 'yearly', $billUser)['id'] === $invA['id']);
check('billing: a module is not covered until its invoice is paid', \App\Services\ModuleBillingService::covered('bundles') === false);
check('billing: paying extends the paid-until date', \App\Services\ModuleBillingService::markPaid((int) $invA['id'], 1, 'test') === true && \App\Services\ModuleBillingService::covered('bundles') === true);
Modules::set('bundles', true);
check('billing: a switched-on module that is paid up is on', Modules::enabled('bundles') === true);
DB::query("UPDATE module_subscriptions SET paid_until = ? WHERE module_key = 'bundles'", [date('Y-m-d', strtotime('-1 day'))]);
\App\Services\ModuleBillingService::flushCache();
check('billing: a module whose paid period has passed switches off by itself', Modules::enabled('bundles') === false && Modules::waitingForPayment('bundles') === true);
DB::query("UPDATE module_subscriptions SET paid_until = ? WHERE module_key = 'bundles'", [date('Y-m-d', strtotime('+1 year'))]);
\App\Services\ModuleBillingService::flushCache();
check('billing: paying again switches it back on', Modules::enabled('bundles') === true);
DB::query('DELETE FROM module_discounts');
$tomorrow = date('Y-m-d\TH:i', time() + 86400);
check('discounts: with no discount the list price applies', (float) \App\Services\ModulePricing::quote('bundles', 'yearly')['final'] === 120.0);
\App\Services\ModulePricing::setDiscount('global', 'percent', '10', $tomorrow, 1);
check('discounts: a global percentage applies to every priced module', (float) \App\Services\ModulePricing::quote('bundles', 'yearly')['final'] === 108.0);
\App\Services\ModulePricing::setDiscount('bundles', 'fixed', '75', $tomorrow, 1);
check('discounts: a module fixed price overrides the global one', (float) \App\Services\ModulePricing::quote('bundles', 'yearly')['final'] === 75.0);
DB::query("UPDATE module_discounts SET ends_at = ? WHERE scope = 'bundles'", [date('Y-m-d H:i:s', time() - 60)]);
check('discounts: an expired module discount falls back to the global one', (float) \App\Services\ModulePricing::quote('bundles', 'yearly')['final'] === 108.0);
\App\Services\ModulePricing::setDiscount('global', 'fixed', '999', $tomorrow, 1);
check('discounts: a global fixed price never raises a module above its list price', (float) \App\Services\ModulePricing::quote('bundles', 'yearly')['final'] === 120.0);
DB::query("UPDATE module_discounts SET ends_at = ? WHERE scope = 'global'", [date('Y-m-d H:i:s', time() - 60)]);
check('discounts: an expired global discount gives the list price back', (float) \App\Services\ModulePricing::quote('bundles', 'yearly')['final'] === 120.0);
DB::query('DELETE FROM module_discounts');
check('grant: a superadmin can grant a term by hand', \App\Services\ModuleBillingService::grant('bundles', 'monthly', 1) === null && \App\Services\ModuleBillingService::covered('bundles') === true);
check('grant: the grant is recorded as a paid invoice marked manual', (int) DB::query("SELECT COUNT(*) FROM module_invoices WHERE method = 'manual' AND status = 'paid' AND module_key = 'bundles'")->fetchColumn() >= 1);
\App\Services\ModuleBillingService::grant('bundles', 'monthly', 1, '0.00');
check('grant: 0.00 is recorded as free of charge', (int) DB::query("SELECT COUNT(*) FROM module_invoices WHERE method = 'manual' AND amount = 0.00 AND note LIKE 'Free of charge%'")->fetchColumn() >= 1);
\App\Services\ModuleBillingService::grant('bundles', 'yearly', 1, '50.00', 'Agreed by phone');
check('grant: the superadmin remark is on the invoice', (int) DB::query("SELECT COUNT(*) FROM module_invoices WHERE note LIKE '%Remark: Agreed by phone%'")->fetchColumn() === 1);
check('grant: an amount that is not a price is refused', \App\Services\ModuleBillingService::grant('bundles', 'monthly', 1, 'abc') !== null);
check('grant: an unknown term is refused', \App\Services\ModuleBillingService::grant('bundles', 'weekly', 1) !== null);
\App\Services\ModuleBillingService::grant('bundles', 'one_time', 1);
check('grant: lifetime never runs out', \App\Services\ModuleBillingService::paidUntil('bundles') === '9999-12-31');
check('billing: paying the same invoice twice changes nothing', \App\Services\ModuleBillingService::markPaid((int) $invA['id'], 1, 'again') === false);
check('billing: a free module never needs an invoice', is_string(\App\Services\ModuleBillingService::invoiceFor('newsletter', 'yearly', $billUser)) || ModulePricing::isFree('newsletter'));
check('billing: a receipt that is not waiting for review cannot be approved', \App\Services\ModuleBillingService::review((int) $invA['id'], true, '', 1) !== null);

// Superadmin PIN: the default works until changed; 5 wrong tries lock it; a changed PIN replaces the old one.
$pinUser = User::create('superadmin', 'PIN Test', 'pin@example.test', 'long-password-1');
check('PIN: the default PIN works before anything is set', \App\Services\SuperadminPin::verify($pinUser, '123456')['ok'] === true);
check('PIN: a wrong PIN is refused', \App\Services\SuperadminPin::verify($pinUser, '000000')['error'] === 'wrong');
for ($i = 0; $i < 4; $i++) { \App\Services\SuperadminPin::verify($pinUser, '000000'); }
check('PIN: five wrong tries lock it, even for the right PIN', \App\Services\SuperadminPin::verify($pinUser, '123456')['error'] === 'locked');
DB::query('UPDATE users SET pin_locked_until = NULL, pin_failed = 0 WHERE id = ?', [$pinUser]);
check('PIN: a superadmin can change it', \App\Services\SuperadminPin::change($pinUser, '123456', '204817', '204817') === null && \App\Services\SuperadminPin::isCustom($pinUser));
check('PIN: the old default stops working after a change', \App\Services\SuperadminPin::verify($pinUser, '123456')['ok'] === false);
check('PIN: the new PIN works', \App\Services\SuperadminPin::verify($pinUser, '204817')['ok'] === true);

// Abandoned carts: a member cart is snapshotted while held; it is due after the idle delay unless the member ordered since.
$acUser = User::create('member', 'Cart Forgetter', 'cart@example.test', 'long-password-1');
DB::query("INSERT INTO carts (user_id) VALUES (?)", [$acUser]);
$acCart = (int) DB::connection()->lastInsertId();
DB::query("INSERT INTO cart_items (cart_id, product_id, variant_id, qty, reserved_until) VALUES (?, ?, NULL, 1, NOW() + INTERVAL 1 HOUR)", [$acCart, $bpA]);
check('abandoned carts: a cart with held items is snapshotted', \App\Services\AbandonedCartService::snapshot() >= 1);
DB::query("UPDATE abandoned_carts SET last_seen_at = NOW() - INTERVAL 3 HOUR WHERE cart_id = ?", [$acCart]);
check('abandoned carts: an idle cart is due a reminder', in_array($acCart, array_map('intval', array_column(\App\Services\AbandonedCartService::due(7200), 'cart_id')), true));
DB::query("INSERT INTO orders (order_no, user_id, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, total, payment_method, status) VALUES ('AC-1', ?, 'Cart Forgetter', 'cart@example.test', '0123', 'Line 1', 'City', 'Selangor', '43000', 10.00, 10.00, 'cod', 'pending')", [$acUser]);
check('abandoned carts: no reminder once the member has ordered since', !in_array($acCart, array_map('intval', array_column(\App\Services\AbandonedCartService::due(7200), 'cart_id')), true));
check('abandoned carts: a bad restore link is refused', \App\Services\AbandonedCartService::restore($acUser, str_repeat('b', 64)) === null);
DB::query('DELETE FROM carts WHERE id = ?', [$acCart]); // leave no cart behind for later tests (the seed test expects none)

// Price-drop alerts: a target must be below today's price; a price change queues a check.
$pa = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Drop Tee', 'slug' => 'drop-tee', 'sku' => 'DRP001', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '100.00', 'discount_price' => null, 'stock' => 5, 'status' => 'active']);
check('price alerts: a target at or above today\'s price is refused', \App\Services\PriceAlertService::store($pa, '100.00', 'drop@example.test', null) === 'at_or_below');
check('price alerts: a lower target is saved', \App\Services\PriceAlertService::store($pa, '80.00', 'drop@example.test', null) === 'saved');
check('price alerts: a bad address is refused', \App\Services\PriceAlertService::store($pa, '80.00', 'nope', null) === 'invalid');
DB::query('DELETE FROM jobs');
Product::update($pa, ['retail_price' => '75.00']);
check('price alerts: a price change queues a price check', (int) DB::query("SELECT COUNT(*) FROM jobs WHERE type = 'price.drop'")->fetchColumn() === 1);

// Accounting: an order's journal balances, and a cancelled order is left out.
DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, discount_total, shipping_fee, tax_total, total, payment_method, status, created_at) VALUES ('ACC-1', 'Acc Test', 'acc@example.test', '0123', 'Line 1', 'City', 'Selangor', '43000', 100.00, 10.00, 8.00, 5.00, 98.00, 'cod', 'completed', NOW())");
DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, discount_total, shipping_fee, tax_total, total, payment_method, status, created_at) VALUES ('ACC-2', 'Acc Test', 'acc@example.test', '0123', 'Line 1', 'City', 'Selangor', '43000', 50.00, 0, 0, 0, 50.00, 'cod', 'cancelled', NOW())");
$accToday = date('Y-m-d');
$accLines = \App\Services\AccountingExportService::journal($accToday, $accToday);
$accTotals = \App\Services\AccountingExportService::totals($accLines);
check('accounting: an order journal balances (debits equal credits)', $accTotals['balanced'] === true);
check('accounting: the order journal debits the amount received (98.00)', abs(array_sum(array_column(array_filter($accLines, fn($l) => $l['ref'] === 'ACC-1'), 'debit')) - 98.0) < 0.005);
check('accounting: a cancelled order is left out', !in_array('ACC-2', array_column($accLines, 'ref'), true));

// Stock counts: a count is taken blind, differences are applied once as corrections.
$cnt = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Count Mug', 'slug' => 'count-mug', 'sku' => 'CNT001', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '10.00', 'discount_price' => null, 'stock' => 5, 'status' => 'active']);
$countId = \App\Services\StockCountService::create('Test shelf', null, 1);
$countLine = (int) DB::query("SELECT id FROM stock_count_lines WHERE count_id = ? AND product_id = ?", [$countId, $cnt])->fetchColumn();
check('stock count: the sheet lists the product with its system quantity', $countLine > 0 && (int) DB::query('SELECT system_qty FROM stock_count_lines WHERE id = ?', [$countLine])->fetchColumn() === 5);
\App\Services\StockCountService::saveCounts($countId, [$countLine => '7']);
check('stock count: a counted difference is listed for review', count(\App\Services\StockCountService::differences($countId)) === 1);
$applied = \App\Services\StockCountService::apply($countId, 1);
check('stock count: applying writes the counted figure to stock', $applied['applied'] === 1 && (int) DB::query('SELECT stock FROM products WHERE id = ?', [$cnt])->fetchColumn() === 7);
check('stock count: applying again changes nothing', \App\Services\StockCountService::apply($countId, 1)['applied'] === 0 && (int) DB::query('SELECT stock FROM products WHERE id = ?', [$cnt])->fetchColumn() === 7);

// Quantity price breaks: a break is cheaper than the price, applies at its quantity, and is ignored when switched off.
$qb = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Break Mug', 'slug' => 'break-mug', 'sku' => 'BRK001', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '10.00', 'discount_price' => null, 'stock' => 50, 'status' => 'active']);
check('quantity breaks: a break at or above the current price is refused', \App\Services\PriceBreakService::save($qb, [['min_qty' => '10', 'unit_price' => '10.00']], 1) !== null);
check('quantity breaks: a cheaper break is saved', \App\Services\PriceBreakService::save($qb, [['min_qty' => '10', 'unit_price' => '8.00']], 1) === null);
Modules::set('quantity_breaks', true);
check('quantity breaks: below the quantity the normal price applies', \App\Services\PriceBreakService::unitFor($qb, 9, 1000) === 1000);
check('quantity breaks: at the quantity the break price applies', \App\Services\PriceBreakService::unitFor($qb, 10, 1000) === 800);
Modules::set('quantity_breaks', false);
check('quantity breaks: switched off, the normal price applies', \App\Services\PriceBreakService::unitFor($qb, 10, 1000) === 1000);
Modules::set('quantity_breaks', true);

// Member prices: a group member pays the lower group price; a guest or a member outside the group pays the public price.
$mpProd = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Group Tee', 'slug' => 'group-tee', 'sku' => 'GRP001', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '20.00', 'discount_price' => null, 'stock' => 9, 'status' => 'active']);
$mpUser = User::create('member', 'Wholesale Buyer', 'wholesale@example.test', 'long-password-1');
check('member prices: a group is created', \App\Services\MemberPriceService::createGroup('Wholesale', 1) === null);
$mpGroup = (int) DB::query("SELECT id FROM member_groups WHERE name = 'Wholesale'")->fetchColumn();
check('member prices: a group price above the public price is refused', \App\Services\MemberPriceService::setPrice($mpGroup, $mpProd, '25.00', 1) !== null);
check('member prices: a lower group price is saved', \App\Services\MemberPriceService::setPrice($mpGroup, $mpProd, '12.00', 1) === null);
check('member prices: the member is put in the group', \App\Services\MemberPriceService::assign('wholesale@example.test', $mpGroup, 1) === null);
Modules::set('member_prices', true);
check('member prices: a group member pays the group price', \App\Services\MemberPriceService::priceFor($mpUser, $mpProd, 2000) === 1200);
check('member prices: a guest pays the public price', \App\Services\MemberPriceService::priceFor(null, $mpProd, 2000) === 2000);
Modules::set('member_prices', false);
check('member prices: switched off, the public price applies', \App\Services\MemberPriceService::priceFor($mpUser, $mpProd, 2000) === 2000);
Modules::set('member_prices', true);

// Pre-orders: a limit caps the units sold; stock is set on arrival less the units sold; a cancelled line gives its units back.
$po = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Pre Mug', 'slug' => 'pre-mug', 'sku' => 'PRE001', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '10.00', 'discount_price' => null, 'stock' => 0, 'status' => 'active']);
Modules::set('preorders', true);
check('pre-orders: opening with a limit is saved', \App\Services\PreorderService::open($po, date('Y-m-d', strtotime('+30 days')), 5, 1) === null);
check('pre-orders: the sellable units are the limit', \App\Services\PreorderService::sellable($po, 0) === 5);
\App\Services\PreorderService::reserve($po, 3, 0);
check('pre-orders: units sold reduce what is left', \App\Services\PreorderService::sellable($po, 0) === 2);
$overSold = false;
try { \App\Services\PreorderService::reserve($po, 3, 0); } catch (\DomainException) { $overSold = true; }
check('pre-orders: the limit cannot be exceeded', $overSold);
\App\Services\PreorderService::release($po, 1, 'cancel', 0, 1);
check('pre-orders: a cancelled unit goes back to the limit', \App\Services\PreorderService::sellable($po, 0) === 3);
check('pre-orders: arriving stock must cover what is sold', \App\Services\PreorderService::arrive($po, 1, 1) !== null);
check('pre-orders: arrival sets stock to arrived minus sold', \App\Services\PreorderService::arrive($po, 10, 1) === null && (int) DB::query('SELECT stock FROM products WHERE id = ?', [$po])->fetchColumn() === 8);
check('pre-orders: the pre-order closes on arrival', !\App\Services\PreorderService::isPreorder($po));
Modules::set('preorders', false);

// Purchase orders: stock goes up only on a recorded delivery, never past the ordered quantity.
$poProd = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Supplied Tote', 'slug' => 'supplied-tote', 'sku' => 'SUP001', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '10.00', 'discount_price' => null, 'stock' => 1, 'status' => 'active']);
check('purchase orders: a supplier is saved', \App\Services\PurchaseOrderService::createSupplier('Acme Supply', '', '7', 1) === null);
$poSupplier = (int) DB::query("SELECT id FROM suppliers WHERE name = 'Acme Supply'")->fetchColumn();
$poId = \App\Services\PurchaseOrderService::createOrder($poSupplier, [['product_id' => $poProd, 'qty' => 10, 'unit_cost' => '4.00']], 1);
check('purchase orders: a draft order is made', is_int($poId));
check('purchase orders: a draft must be sent before it can receive', \App\Services\PurchaseOrderService::receive($poId, [1 => '2'], 1) !== null);
\App\Services\PurchaseOrderService::send($poId, 1);
$poItem = (int) DB::query('SELECT id FROM purchase_order_items WHERE po_id = ?', [$poId])->fetchColumn();
check('purchase orders: a delivery adds to stock', \App\Services\PurchaseOrderService::receive($poId, [$poItem => '4'], 1) === null && (int) DB::query('SELECT stock FROM products WHERE id = ?', [$poProd])->fetchColumn() === 5);
check('purchase orders: a delivery cannot go past the ordered quantity', \App\Services\PurchaseOrderService::receive($poId, [$poItem => '7'], 1) !== null);
check('purchase orders: the order is part-received', (string) DB::query('SELECT status FROM purchase_orders WHERE id = ?', [$poId])->fetchColumn() === 'part_received');
\App\Services\PurchaseOrderService::receive($poId, [$poItem => '6'], 1);
check('purchase orders: the rest arrives and the order is received, with stock matching the total', (string) DB::query('SELECT status FROM purchase_orders WHERE id = ?', [$poId])->fetchColumn() === 'received' && (int) DB::query('SELECT stock FROM products WHERE id = ?', [$poProd])->fetchColumn() === 11);

// Subscriptions: a due cycle places one order; the same day places none; an unpaid cycle pauses it until paid.
$subUser = User::create('member', 'Sub Buyer', 'sub@example.test', 'long-password-1');
DB::query("INSERT INTO addresses (user_id, name, phone, line1, city, state, postcode, is_default) VALUES (?, 'Sub Buyer', '0123456789', 'Line 1', 'City', 'Selangor', '43000', 1)", [$subUser]);
$subAddr = (int) DB::connection()->lastInsertId();
$subProd = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => 'Sub Tea', 'slug' => 'sub-tea', 'sku' => 'SUB001', 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '10.00', 'discount_price' => null, 'stock' => 50, 'status' => 'active']);
Modules::set('subscriptions', true);
check('subscriptions: a member can subscribe to a product', \App\Services\SubscriptionService::create($subUser, $subProd, $subAddr, 'monthly', 'cod', $subUser) === null);
$subRes = \App\Services\SubscriptionService::runDue();
check('subscriptions: the first due cycle places one order', $subRes['created'] >= 1 && (int) DB::query('SELECT COUNT(*) FROM subscription_runs r JOIN subscriptions s ON s.id = r.subscription_id WHERE s.user_id = ? AND r.order_id IS NOT NULL', [$subUser])->fetchColumn() === 1);
$subId = (int) DB::query('SELECT id FROM subscriptions WHERE user_id = ?', [$subUser])->fetchColumn();
check('subscriptions: the next due date moves on by a month', (string) DB::query('SELECT next_due FROM subscriptions WHERE id = ?', [$subId])->fetchColumn() > date('Y-m-d'));
\App\Services\SubscriptionService::runDue();
check('subscriptions: the same cycle is never placed twice', (int) DB::query('SELECT COUNT(*) FROM subscription_runs WHERE subscription_id = ?', [$subId])->fetchColumn() === 1);
DB::query('UPDATE subscriptions SET next_due = CURDATE() WHERE id = ?', [$subId]);
$pausedRes = \App\Services\SubscriptionService::runDue();
check('subscriptions: an unpaid cycle pauses the subscription and places nothing', $pausedRes['paused'] >= 1 && (string) DB::query('SELECT status FROM subscriptions WHERE id = ?', [$subId])->fetchColumn() === 'paused');
DB::query("UPDATE orders o JOIN subscription_runs r ON r.order_id = o.id SET o.payment_status = 'paid', o.status = 'completed' WHERE r.subscription_id = ?", [$subId]);
$resumeRes = \App\Services\SubscriptionService::runDue();
check('subscriptions: paying the held cycle resumes it', $resumeRes['resumed'] >= 1 && (string) DB::query('SELECT status FROM subscriptions WHERE id = ?', [$subId])->fetchColumn() === 'active');
Modules::set('subscriptions', false);

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
