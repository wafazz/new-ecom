<?php
// Moving printed Processing orders on: COD to Shipped, paid non-COD to Shipped then Completed, everything else skipped. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Services\OrderService;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Move Admin', 'move@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();

$mk = function (string $status, string $method, string $pay, ?string $printed): int {
    DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES (?, 'Ann', 'ann@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 90, 0, 90, ?, ?, ?)", ['M-' . bin2hex(random_bytes(3)), $method, $pay, $status]);
    $id = (int) DB::connection()->lastInsertId();
    if ($printed !== null) {
        DB::query("INSERT INTO shipments (order_id, tracking_number, service_level, weight_kg, delivery_date, delivery_timeslot, active, awb_printed_at) VALUES (?, ?, 'Standard', 1, '2026-11-01', 'AM', 1, " . ($printed === 'now' ? 'NOW()' : 'NULL') . ")", [$id, 'MV' . $id]);
    }
    return $id;
};
$status = fn(int $id) => DB::query('SELECT status FROM orders WHERE id = ?', [$id])->fetchColumn();
$pay = fn(int $id) => DB::query('SELECT payment_status FROM orders WHERE id = ?', [$id])->fetchColumn();

$cod = $mk('processing', 'cod', 'unpaid', 'now');
$prepaid = $mk('processing', 'billplz', 'paid', 'now');
$unpaidPrepaid = $mk('processing', 'billplz', 'unpaid', 'now');
$notPrinted = $mk('processing', 'cod', 'unpaid', null);
$noBooking = $mk('processing', 'cod', 'unpaid', 'none');
$pending = $mk('pending', 'cod', 'unpaid', 'now');

check('idsFrom keeps positive unique ids, at most 50', OrderService::idsFrom('3,1,1,0,x,2') === [3, 1, 2] && count(OrderService::idsFrom(implode(',', range(1, 80)))) === 50 && OrderService::idsFrom('') === []);

$r = OrderService::moveNext([$cod, $prepaid, $unpaidPrepaid, $notPrinted, $noBooking, $pending, 999999], $actor);
check('a printed COD order goes to Shipped (payment stays as it was)', $status($cod) === 'shipped' && $pay($cod) === 'unpaid');
check('a paid non-COD order goes to Completed', $status($prepaid) === 'completed' && $pay($prepaid) === 'paid');
check('an unpaid non-COD order is skipped and stays Processing', $status($unpaidPrepaid) === 'processing');
check('an order without a printed label is skipped', $status($notPrinted) === 'processing');
check('an order without a courier booking is skipped', $status($noBooking) === 'processing');
check('a pending order is skipped, unknown ids are reported', $status($pending) === 'pending' && count($r['skipped']) === 5);
check('the counts match what moved', $r['shipped'] === 1 && $r['completed'] === 1);
check('each move is in the order history', (int) DB::query("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND event = 'shipped'", [$cod])->fetchColumn() === 1 && (int) DB::query("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND event IN ('shipped','completed')", [$prepaid])->fetchColumn() === 2);
check('the move is logged in the audit trail without customer details', (int) DB::query("SELECT COUNT(*) FROM audit_logs WHERE action = 'orders.move_next' AND detail LIKE '%completed 1%'")->fetchColumn() === 1);

// tidy up (a courier booking must go before its order)
DB::query('DELETE FROM shipments');
DB::query("DELETE FROM orders WHERE order_no LIKE 'M-%'");
