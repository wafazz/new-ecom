<?php
// DB-backed cart hold checks: stock held by a cart line, lapsed lines dropped, strict checkout, admin setting.
// Skipped unless DB_NAME ends in `_test` (never touches a real database):
//   DB_NAME=ecommerce_test php bin/migrate.php --create-db && DB_NAME=ecommerce_test php tests/run.php
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService as CS;
use App\Services\CheckoutException;
use App\Services\CheckoutService as C;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}

reset_test_db();
$pid = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Hold Mug', 'slug' => 'hold-mug', 'sku' => 'H1', 'base_price' => '50.00', 'company_base_price' => '70.00', 'retail_price' => '100.00', 'stock' => 5, 'status' => 'active']);
$contact = ['name' => 'Buyer', 'email' => 'hold@db.test', 'phone' => '0123456789', 'line1' => '1 Jalan', 'line2' => '', 'city' => 'KL', 'state' => 'Kuala Lumpur', 'postcode' => '50000', 'notes' => ''];
$stock = fn() => (int) DB::query('SELECT stock FROM products WHERE id = ?', [$pid])->fetchColumn();
$newCart = function (): int {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [bin2hex(random_bytes(32))]);
    return (int) DB::connection()->lastInsertId();
};
// A line whose hold ends at $until (an SQL expression), so tests can make it lapse or run short.
$line = function (int $cart, int $qty, string $until = 'NOW() + INTERVAL 15 MINUTE') use ($pid): void {
    DB::query("INSERT INTO cart_items (cart_id, product_id, qty, reserved_until) VALUES (?,?,?, {$until})", [$cart, $pid, $qty]);
};
// Make $cart the current cart for the next CartService call (the storefront reads it from a cookie).
$asCart = function (int $cart): void {
    $token = bin2hex(random_bytes(32));
    DB::query('UPDATE carts SET session_hash = ? WHERE id = ?', [hash('sha256', $token), $cart]);
    $_COOKIE['cart_token'] = $token;
};

// --- stock held by cart lines -------------------------------------------------------------------------------
$a = $newCart();
$line($a, 3);
$b = $newCart();
check('units in another cart\'s hold are not free', CS::heldByOthers($pid, 0, $b) === 3);
check('your own hold does not count against you', CS::heldByOthers($pid, 0, $a) === 0);

$asCart($b);
check('add is refused when only part of the stock is free', CS::add($pid, null, 3) === 'Only 2 available.');
check('add within what is free succeeds', CS::add($pid, null, 2) === null);
$bHold = (int) DB::query('SELECT reserved_until > NOW() FROM cart_items WHERE cart_id = ?', [$b])->fetchColumn();
check('a new add gets a hold running from now', $bHold === 1);

// --- lapsed holds ---------------------------------------------------------------------------------------------
DB::query('UPDATE cart_items SET reserved_until = NOW() - INTERVAL 1 MINUTE WHERE cart_id = ?', [$a]);
check('a lapsed hold stops counting against stock', CS::heldByOthers($pid, 0, $b) === 0);
$asCart($a);
$sum = CS::summary();
check('a lapsed line is dropped silently from the cart', $sum['items'] === [] && (int) DB::query('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$a])->fetchColumn() === 0);

// An expired line must not add its old qty to a new add.
$v = $newCart();
$line($v, 3, 'NOW() - INTERVAL 1 MINUTE');
$asCart($v);
CS::add($pid, null, 1);
check('adding after a lapse starts from zero, not the old qty', (int) DB::query('SELECT qty FROM cart_items WHERE cart_id = ? AND product_id = ?', [$v, $pid])->fetchColumn() === 1);

// --- strict checkout --------------------------------------------------------------------------------------------
DB::query('DELETE FROM cart_items');
DB::query('DELETE FROM carts');
$c = $newCart();
$line($c, 1, 'NOW() - INTERVAL 1 MINUTE');
$before = $stock();
$msg = null;
try {
    C::place($c, $contact, 'cod', null);
} catch (CheckoutException $e) {
    $msg = $e->getMessage();
}
check('checkout refuses a line whose hold lapsed', $msg === CS::EXPIRED_MESSAGE);
check('a refused checkout takes no stock', $stock() === $before);

// Checkout uses stock net of other carts' holds.
DB::query('DELETE FROM cart_items');
DB::query('DELETE FROM carts');
$f = $newCart();
$line($f, 1);
$g = $newCart();
$line($g, 4);
$placed = C::place($g, $contact, 'cod', null);
check('checkout succeeds when the stock is free after other holds', (int) $placed['order_id'] > 0 && $stock() === 1);

$h = $newCart();
$line($h, 2);
$msg = null;
try {
    C::place($h, $contact, 'cod', null);
} catch (CheckoutException $e) {
    $msg = $e->getMessage();
}
check('checkout refuses units another cart is holding', $msg !== null && str_contains($msg, 'sold out'));

// --- refresh rules ------------------------------------------------------------------------------------------
DB::query('DELETE FROM cart_items');
DB::query('UPDATE products SET stock = 5 WHERE id = ?', [$pid]);
$u = $newCart();
$line($u, 1, 'NOW() + INTERVAL 1 MINUTE');
$item = (int) DB::query('SELECT id FROM cart_items WHERE cart_id = ?', [$u])->fetchColumn();
$asCart($u);
CS::update([$item => 1]);
check('an unchanged line keeps its old hold', (int) DB::query('SELECT reserved_until <= NOW() + INTERVAL 10 MINUTE FROM cart_items WHERE id = ?', [$item])->fetchColumn() === 1);
CS::update([$item => 2]);
check('changing a line gives it a fresh hold', (int) DB::query('SELECT reserved_until > NOW() + INTERVAL 10 MINUTE FROM cart_items WHERE id = ?', [$item])->fetchColumn() === 1);

// --- admin setting -------------------------------------------------------------------------------------------
Setting::set('cart_hold_minutes', '30');
check('the admin hold length is used', CS::holdMinutes() === 30);
Setting::set('cart_hold_minutes', '999');
check('the hold length is capped at 120 minutes', CS::holdMinutes() === 120);
Setting::set('cart_hold_minutes', '0');
check('the hold length is at least 1 minute', CS::holdMinutes() === 1);
Setting::set('cart_hold_minutes', '15');
check('the hold length defaults to 15 minutes', CS::holdMinutes() === 15);

// --- add rate limit (CartController::add: 30 adds per IP per 10 minutes) ---------------------------------------
DB::query('DELETE FROM rate_hits WHERE bucket = ?', ['cart_add']);
for ($i = 0; $i < 30; $i++) {
    App\Services\RateLimiter::hit('cart_add', '203.0.113.9');
}
check('the 31st add from one IP is blocked', App\Services\RateLimiter::tooMany('cart_add', '203.0.113.9', 30, 10));
check('another IP is not blocked', !App\Services\RateLimiter::tooMany('cart_add', '203.0.113.10', 30, 10));
