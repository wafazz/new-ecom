<?php
// DB-backed checkout + affiliate checks. Skipped unless DB_NAME ends in `_test` (never touches a real database):
//   DB_NAME=ecommerce_test php bin/migrate.php --create-db && DB_NAME=ecommerce_test php tests/run.php
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\User;
use App\Services\AffiliateService as A;
use App\Services\CheckoutException;
use App\Services\CheckoutService as C;
use App\Services\OrderService as O;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}

reset_test_db();
$affId = User::create('member', 'Aff', 'aff@db.test', 'Passw0rd!x');
$code = A::ensureCode($affId);
$pid = Product::insert(['name' => 'Widget', 'slug' => 'widget', 'sku' => 'W1', 'base_price' => '50.00', 'company_base_price' => '70.00', 'retail_price' => '100.00', 'stock' => 5, 'status' => 'active']);
$contact = fn(string $email) => ['name' => 'Buyer', 'email' => $email, 'phone' => '0123456789', 'line1' => '1 Jalan', 'line2' => '', 'city' => 'KL', 'state' => 'Kuala Lumpur', 'postcode' => '50000', 'notes' => ''];
$cart = function (int $qty) use ($pid): int {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [bin2hex(random_bytes(32))]);
    $id = (int) DB::connection()->lastInsertId();
    DB::query('INSERT INTO cart_items (cart_id, product_id, qty) VALUES (?,?,?)', [$id, $pid, $qty]);
    return $id;
};
$stock = fn() => (int) DB::query('SELECT stock FROM products WHERE id = ?', [$pid])->fetchColumn();

$r = C::place($cart(2), $contact('b1@db.test'), 'cod', null, null, $code);
check('order placed, stock 5 -> 3', $stock() === 3);
check('order attributed to the affiliate', (int) O::find($r['order_id'])['affiliate_id'] === $affId);
check('guest gets a tracking token', is_string($r['token']) && strlen($r['token']) === 32);

$self = C::place($cart(1), $contact('AFF@db.test'), 'cod', null, null, $code);
check('buyer with the affiliate email is not attributed', O::find($self['order_id'])['affiliate_id'] === null);
$none = C::place($cart(1), $contact('b3@db.test'), 'cod', null, null, "x' OR 1=1");
check('forged ref code ignored', O::find($none['order_id'])['affiliate_id'] === null);

$over = false;
try { C::place($cart(99), $contact('b4@db.test'), 'cod', null); } catch (CheckoutException $e) { $over = true; }
check('over-stock quantity rejected, stock untouched', $over && $stock() === 1);

$oid = $r['order_id'];
foreach (['processing', 'shipped'] as $to) { O::transition($oid, $to, null); }
check('no commission before completion', (int) DB::query('SELECT COUNT(*) FROM affiliate_commissions')->fetchColumn() === 0);
O::completeFromCourier($oid, 'NinjaVan: Successful Delivery');
O::completeFromCourier($oid, 'NinjaVan: Successful Delivery'); // a repeated webhook changes nothing
$rows = DB::query('SELECT * FROM affiliate_commissions')->fetchAll();
$ordRow = O::find($oid);
$expBase = max(0, (int) round((float) $ordRow['subtotal'] * 100) - (int) round((float) $ordRow['shipping_fee'] * 100)); // sales minus shipping
$expAmount = intdiv($expBase * 1000 + 5000, 10000);                                                              // 10% of that base
check('completion creates one pending commission: 10% of (sales - shipping)', count($rows) === 1 && (int) round((float) $rows[0]['base_amount'] * 100) === $expBase && (int) round((float) $rows[0]['amount'] * 100) === $expAmount && $rows[0]['status'] === 'pending');

$cid = (int) $rows[0]['id'];
check('payout refused before approval', A::payout($affId, $affId, 'REF', null) !== null);
check('approve ok, second approve refused', A::decide($cid, 'approved', $affId) === null && A::decide($cid, 'approved', $affId) !== null);
check('payout ok then cannot repeat', A::payout($affId, $affId, 'REF', null) === null && A::payout($affId, $affId, 'REF2', null) !== null);
check('paid commission cannot be rejected', A::decide($cid, 'rejected', $affId) !== null);
check('stats: paid equals the commission, nothing pending/approved', A::stats($affId)['paid'] === $rows[0]['amount'] && A::stats($affId)['pending'] === '0.00' && A::stats($affId)['approved'] === '0.00');

$cancel = $self['order_id'];
O::transition($cancel, 'cancelled', null);
check('cancel restocks exactly once', $stock() === 2 && O::transition($cancel, 'cancelled', null) !== null && $stock() === 2);
check('cancelled order earns no commission', (int) DB::query('SELECT COUNT(*) FROM affiliate_commissions WHERE order_id = ?', [$cancel])->fetchColumn() === 0);
