<?php
// Returned: a shipped parcel sent back to the seller. Stock goes back (as "returned"), and returned is final. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Product;
use App\Services\OrderService as O;
use App\Services\StockService as S;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Ret Admin', 'ret@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();
$p = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Lure', 'slug' => 'lure', 'sku' => 'LURE-R', 'category_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '30', 'stock' => 10, 'status' => 'active']);
$stock = fn() => (int) Product::find($p)['stock'];

$mk = function (string $status) use ($p, $actor): int {
    DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES (?, 'Ann', 'ann@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 90, 0, 90, 'cod', 'unpaid', 'pending')", ['R-' . bin2hex(random_bytes(3))]);
    $id = (int) DB::connection()->lastInsertId();
    DB::query("INSERT INTO order_items (order_id, product_id, product_name, unit_price, retail_price, base_price, company_base_price, qty, line_total) VALUES (?, ?, 'Lure', 30, 30, 5, 10, 3, 90)", [$id, $p]);
    S::delta($p, null, -3, 'order', $id, $actor);
    if ($status !== 'pending') {
        O::transition($id, 'processing', $actor);
    }
    if (in_array($status, ['shipped', 'returned'], true)) {
        O::transition($id, 'shipped', $actor);
    }
    return $id;
};

$shipped = $mk('shipped');
check('a shipped order can be marked returned', O::transition($shipped, 'returned', $actor) === null && DB::query('SELECT status FROM orders WHERE id = ?', [$shipped])->fetchColumn() === 'returned');
check('returned puts the stock back (7 -> 10)', $stock() === 10);
check('the stock history says "Order returned", not cancelled', (int) DB::query("SELECT COUNT(*) FROM stock_movements WHERE order_id = ? AND reason = 'return' AND change_qty = 3", [$shipped])->fetchColumn() === 1);
check('returned is final: no further moves and no second restock', O::transition($shipped, 'completed', $actor) !== null && O::transition($shipped, 'cancelled', $actor) !== null && $stock() === 10 && O::FLOW['returned'] === []);

$proc = $mk('processing');
check('a processing order cannot jump straight to returned', O::transition($proc, 'returned', $actor) !== null && DB::query('SELECT status FROM orders WHERE id = ?', [$proc])->fetchColumn() === 'processing');
check('the status is offered as the next step after shipped', in_array('returned', O::FLOW['shipped'], true));
$cancelled = $mk('processing');
check('cancelling still restocks with the cancel reason, not return', O::transition($cancelled, 'cancelled', $actor) === null && (int) DB::query("SELECT COUNT(*) FROM stock_movements WHERE order_id = ? AND reason = 'cancel'", [$cancelled])->fetchColumn() === 1 && (int) DB::query("SELECT COUNT(*) FROM stock_movements WHERE order_id = ? AND reason = 'return'", [$cancelled])->fetchColumn() === 0);

// tidy up
DB::query("DELETE FROM orders WHERE order_no LIKE 'R-%'");
