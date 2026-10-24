<?php
// Editing an order's contact and delivery details: allowed only before the courier has the order. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Services\OrderService;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Edit Admin', 'edit@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();

$mkOrder = function (string $status, bool $booked = false): int {
    DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES (?, 'Ann', 'ann@example.test', '0123456789', '1 Jalan Lama', 'KL', 'Selangor', '47300', 'MY', 90, 0, 90, 'cod', 'unpaid', ?)", ['E-' . bin2hex(random_bytes(3)), $status]);
    $id = (int) DB::connection()->lastInsertId();
    if ($booked) {
        DB::query("INSERT INTO shipments (order_id, tracking_number, service_level, weight_kg, delivery_date, delivery_timeslot, active) VALUES (?, 'NV2', 'std', 1, '2026-11-01', 'AM', 1)", [$id]);
    }
    return $id;
};
$base = ['customer_name' => 'Ann', 'customer_phone' => '0123456789', 'ship_line1' => '1 Jalan Lama', 'ship_line2' => null, 'ship_city' => 'KL', 'ship_state' => 'Selangor', 'ship_postcode' => '47300'];
$row = fn(int $id) => DB::query('SELECT * FROM orders WHERE id = ?', [$id])->fetch();

$o1 = $mkOrder('pending');
check('nothing changed: no error and no history entry', OrderService::updateDetails($o1, $base, $actor) === null && (int) DB::query("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND event = 'edited'", [$o1])->fetchColumn() === 0);

$new = array_merge($base, ['ship_line1' => '9 Jalan Baru', 'ship_postcode' => '47400', 'customer_name' => 'Ann Lee']);
check('a pending order can be edited', OrderService::updateDetails($o1, $new, $actor) === null && $row($o1)['ship_line1'] === '9 Jalan Baru' && $row($o1)['ship_postcode'] === '47400' && $row($o1)['customer_name'] === 'Ann Lee');
check('history says which fields changed, not the values', DB::query("SELECT note FROM order_events WHERE order_id = ? AND event = 'edited'", [$o1])->fetchColumn() === 'Changed: name, address line 1, postcode');
check('the audit log names the fields, never the values', (int) DB::query("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.edit' AND detail LIKE '%address line 1%' AND detail NOT LIKE '%9 Jalan%' AND detail NOT LIKE '%Ann Lee%'")->fetchColumn() >= 1);

$o2 = $mkOrder('processing');
check('a processing order without a courier can be edited', OrderService::updateDetails($o2, array_merge($base, ['ship_city' => 'Shah Alam']), $actor) === null && $row($o2)['ship_city'] === 'Shah Alam');

$o3 = $mkOrder('processing', true);
check('a courier-booked order cannot be edited', OrderService::updateDetails($o3, array_merge($base, ['ship_city' => 'Ipoh']), $actor) !== null && $row($o3)['ship_city'] === 'KL');

$o4 = $mkOrder('pending');
OrderService::transition($o4, 'cancelled', $actor);
check('a cancelled order cannot be edited', OrderService::updateDetails($o4, array_merge($base, ['ship_city' => 'Ipoh']), $actor) !== null && $row($o4)['ship_city'] === 'KL');
check('a missing order gives a clear error', OrderService::updateDetails(999999, $base, $actor) === 'Order not found.');

// tidy up (a courier booking must go before its order)
DB::query('DELETE FROM shipments');
DB::query("DELETE FROM orders WHERE order_no LIKE 'E-%'");
