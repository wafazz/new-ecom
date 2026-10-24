<?php
// Deleting pending / processing orders: stock goes back, courier-booked and finished orders are kept. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\StockService as S;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Del Admin', 'del@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();
$p = Product::insert(['name' => 'Lure', 'slug' => 'lure', 'sku' => 'LURE-1', 'category_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '30', 'stock' => 10, 'status' => 'active']);
$stock = fn() => (int) Product::find($p)['stock'];

// An order that has taken 3 of the 10 in stock (as checkout does), with a status and optional courier booking.
$mkOrder = function (string $status, bool $booked = false) use ($p, $actor): int {
    DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES (?, 'Ann', 'ann@example.test', '0123', '1 St', 'KL', 'Selangor', '47300', 'MY', 90, 0, 90, 'cod', 'unpaid', ?)", ['D-' . bin2hex(random_bytes(3)), $status]);
    $id = (int) DB::connection()->lastInsertId();
    DB::query("INSERT INTO order_items (order_id, product_id, product_name, unit_price, retail_price, base_price, company_base_price, qty, line_total) VALUES (?, ?, 'Lure', 30, 30, 5, 10, 3, 90)", [$id, $p]);
    S::delta($p, null, -3, 'order', $id, $actor);
    if ($booked) {
        DB::query("INSERT INTO shipments (order_id, tracking_number, service_level, weight_kg, delivery_date, delivery_timeslot, active) VALUES (?, 'NV1', 'std', 1, '2026-11-01', 'AM', 0)", [$id]);
    }
    return $id;
};

$o1 = $mkOrder('pending');
$after = $stock();
check('an order placed from stock: 3 taken', $after === 7, (string) $after);

$err = OrderService::delete($o1, $actor);
check('a pending order can be removed from the list', $err === null, (string) $err);
check('its stock goes back (7 -> 10)', $stock() === 10);
check('removing keeps the order as Cancelled, with its items and stock history', DB::query('SELECT status FROM orders WHERE id = ?', [$o1])->fetchColumn() === 'cancelled' && (int) DB::query('SELECT COUNT(*) FROM order_items WHERE order_id = ?', [$o1])->fetchColumn() === 1 && (int) DB::query('SELECT COUNT(*) FROM stock_movements WHERE order_id = ?', [$o1])->fetchColumn() === 2);
check('the delete is in the audit log, with no customer details', (int) DB::query("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.delete' AND actor_id = ? AND detail NOT LIKE '%ann@%'", [$actor])->fetchColumn() === 1);

$o2 = $mkOrder('processing');
check('a processing order without a courier can be deleted', OrderService::delete($o2, $actor) === null && $stock() === 10);

$o3 = $mkOrder('processing', true);
$s3 = $stock();
check('a processing order with a courier booking is kept', OrderService::delete($o3, $actor) !== null && (int) DB::query('SELECT COUNT(*) FROM orders WHERE id = ?', [$o3])->fetchColumn() === 1 && $stock() === $s3);

$o4 = $mkOrder('pending');
OrderService::transition($o4, 'cancelled', $actor);
$s4 = $stock();
check('a cancelled order is kept (its stock is already back)', OrderService::delete($o4, $actor) !== null && $stock() === $s4);

$o5 = $mkOrder('pending');
OrderService::transition($o5, 'processing', $actor);
OrderService::transition($o5, 'shipped', $actor);
check('a shipped order is kept', OrderService::delete($o5, $actor) !== null);
check('a missing order gives a clear error', OrderService::delete(999999, $actor) === 'Order not found.');

// tidy up (a courier booking must go before its order)
DB::query('DELETE FROM shipments');
DB::query('DELETE FROM orders WHERE order_no LIKE ?', ['D-%']);
