<?php
// DB-backed digital product checks: no postage, no stock for digital lines, COD refused, grants on payment,
// download limits and expiry, license keys. Skipped unless DB_NAME ends in `_test` (never touches a real database).
use App\Core\Database as DB;
use App\Core\SecretBox;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutException;
use App\Services\CheckoutService as C;
use App\Services\DigitalService as D;
use App\Services\OrderService as O;
use App\Services\ShippingRates as SR;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}

reset_test_db();
$adminId = User::create('admin', 'Admin', 'admin@digital.test', 'Passw0rd!x');
$rod = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Rod', 'slug' => 'rod', 'sku' => 'R1', 'base_price' => '50.00', 'company_base_price' => '70.00', 'retail_price' => '100.00', 'stock' => 5, 'status' => 'active']);
$ebook = Product::insert(['name' => 'Ebook', 'slug' => 'ebook', 'base_price' => '10.00', 'company_base_price' => '15.00', 'retail_price' => '20.00', 'stock' => 0, 'status' => 'active',
    'product_type' => 'digital', 'digital_delivery' => 'file', 'digital_file_path' => 'digital-test-file', 'digital_file_name' => 'guide.pdf', 'digital_expiry_days' => 30, 'digital_max_downloads' => 2]);
$keys = Product::insert(['name' => 'Licence', 'slug' => 'licence', 'base_price' => '10.00', 'company_base_price' => '15.00', 'retail_price' => '20.00', 'stock' => 0, 'status' => 'active',
    'product_type' => 'digital', 'digital_delivery' => 'keys', 'digital_expiry_days' => 30, 'digital_max_downloads' => 5]);
D::addKeys($keys, ['KEY-AAA', 'KEY-BBB']);

// The stored file the download test serves. Removed at the end.
if (!is_dir(D::dir())) {
    mkdir(D::dir(), 0750, true);
}
file_put_contents(D::dir() . '/digital-test-file', 'test');

$contact = fn(string $email) => ['name' => 'Buyer', 'email' => $email, 'phone' => '0123456789', 'line1' => '1 Jalan', 'line2' => '', 'city' => 'KL', 'state' => 'Kuala Lumpur', 'postcode' => '50000', 'notes' => ''];
$cart = function (array $lines): int {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [bin2hex(random_bytes(32))]);
    $id = (int) DB::connection()->lastInsertId();
    foreach ($lines as [$pid, $qty]) {
        DB::query('INSERT INTO cart_items (cart_id, product_id, qty) VALUES (?,?,?)', [$id, $pid, $qty]);
    }
    return $id;
};
$productStock = fn(int $id) => (int) DB::query('SELECT stock FROM products WHERE id = ?', [$id])->fetchColumn();
$movements = fn(int $id) => (int) DB::query('SELECT COUNT(*) FROM stock_movements WHERE product_id = ?', [$id])->fetchColumn();

// Postage: a digital line adds nothing, so a mixed cart costs the same as its physical lines alone.
$physicalOnly = SR::quote([['weight_kg' => '1.000', 'qty' => 1, 'digital' => false]], 10000, 'Kuala Lumpur', 'bank_transfer');
$mixed = SR::quote([['weight_kg' => '1.000', 'qty' => 1, 'digital' => false], ['weight_kg' => null, 'qty' => 1, 'digital' => true]], 10000, 'Kuala Lumpur', 'bank_transfer');
check('a digital line adds no postage in a mixed cart', $mixed['fee'] === $physicalOnly['fee']);
$none = SR::quote([['weight_kg' => null, 'qty' => 1, 'digital' => true]], 2000, 'Sabah', 'bank_transfer');
check('an all-digital cart has zero postage', $none['fee'] === 0 && $none['zone'] === null);

// Digital-only order: no postage, no stock taken.
$ebookOrder = C::place($cart([[$ebook, 1]]), $contact('ebook@digital.test'), 'bank_transfer', null);
$o = O::find($ebookOrder['order_id']);
check('digital-only order has no shipping fee', (float) $o['shipping_fee'] === 0.0);
check('digital item records its delivery', (string) O::items($ebookOrder['order_id'])[0]['digital_delivery'] === 'file');
check('digital item takes no stock movement', $movements($ebook) === 0);

// Cash on delivery is refused when the cart has a digital product.
try {
    C::place($cart([[$ebook, 1]]), $contact('cod@digital.test'), 'cod', null);
    check('cash on delivery refused for digital', false);
} catch (CheckoutException) {
    check('cash on delivery refused for digital', true);
}

// Paying grants the download; the link works up to its limit, then stops.
O::markPaid($ebookOrder['order_id'], $adminId);
$grant = DB::query('SELECT id, token_enc, max_downloads FROM digital_grants WHERE order_id = ?', [$ebookOrder['order_id']])->fetch();
check('paying creates one download grant', $grant !== false && (int) $grant['max_downloads'] === 2);
$token = SecretBox::decrypt((string) $grant['token_enc']);
check('the grant token decrypts for the order page', is_string($token) && strlen($token) === 48);
check('a wrong token is refused', !D::claim(str_repeat('0', 48))['ok']);
check('first download allowed', D::claim($token)['ok'] === true);
check('second download allowed', D::claim($token)['ok'] === true);
$third = D::claim($token);
check('third download refused (limit 2)', !$third['ok'] && $third['code'] === 410);
check('download count stops at the limit', (int) DB::query('SELECT downloads FROM digital_grants WHERE id = ?', [(int) $grant['id']])->fetchColumn() === 2);

// Expired link.
DB::query('UPDATE digital_grants SET expires_at = NOW() - INTERVAL 1 DAY, downloads = 0 WHERE id = ?', [(int) $grant['id']]);
$expired = D::claim($token);
check('expired link refused', !$expired['ok'] && $expired['code'] === 410);
DB::query('UPDATE digital_grants SET expires_at = NOW() + INTERVAL 30 DAY WHERE id = ?', [(int) $grant['id']]);

// A cancelled order does not download.
$cancelOrder = C::place($cart([[$ebook, 1]]), $contact('cancel@digital.test'), 'bank_transfer', null);
O::markPaid($cancelOrder['order_id'], $adminId);
$cancelToken = SecretBox::decrypt((string) DB::query('SELECT token_enc FROM digital_grants WHERE order_id = ?', [$cancelOrder['order_id']])->fetchColumn());
O::transition($cancelOrder['order_id'], 'cancelled', $adminId);
check('cancelled order download refused', !D::claim((string) $cancelToken)['ok']);

// License keys: one per unit sold, and sold-out when the pool is empty.
$keyOrder = C::place($cart([[$keys, 2]]), $contact('keys@digital.test'), 'bank_transfer', null);
check('two free keys before payment', D::freeKeys($keys) === 2);
O::markPaid($keyOrder['order_id'], $adminId);
$keyItem = (int) O::items($keyOrder['order_id'])[0]['id'];
$sold = D::keysFor($keyItem);
check('paid order gets its two keys', $sold === ['KEY-AAA', 'KEY-BBB']);
check('no free keys left', D::freeKeys($keys) === 0);
try {
    C::place($cart([[$keys, 1]]), $contact('sold@digital.test'), 'bank_transfer', null);
    check('sold-out keys refused at checkout', false);
} catch (CheckoutException) {
    check('sold-out keys refused at checkout', true);
}

// Physical stock is still taken for physical lines.
C::place($cart([[$rod, 1], [$ebook, 1]]), $contact('mixed@digital.test'), 'bank_transfer', null);
check('physical line still takes stock in a mixed cart', $productStock($rod) === 4 && $movements($ebook) === 0);

@unlink(D::dir() . '/digital-test-file');
