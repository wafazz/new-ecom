<?php
// COD orders complete only from the courier's delivery update; prepaid orders are unchanged. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Services\OrderService as O;
use App\Services\Shipping\NinjaVanGateway as G;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Cod Admin', 'cod@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();

$mk = function (string $method, string $pay, string $status, string $tn): int {
    DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES (?, 'Ann', 'ann@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 90, 0, 90, ?, ?, ?)", ['C-' . bin2hex(random_bytes(3)), $method, $pay, $status]);
    $id = (int) DB::connection()->lastInsertId();
    DB::query("INSERT INTO shipments (order_id, tracking_number, service_level, weight_kg, delivery_date, delivery_timeslot, active) VALUES (?, ?, 'Standard', 1, '2026-11-01', 'AM', 1)", [$id, $tn]);
    return $id;
};
$event = fn(string $tn, string $status, string $uid) => ['tracking_id' => $tn, 'status' => $status, 'id' => $uid, 'timestamp' => date('c')];
$row = fn(int $id) => DB::query('SELECT status, payment_status FROM orders WHERE id = ?', [$id])->fetch();

$cod = $mk('cod', 'unpaid', 'shipped', 'CODT1');
check('a COD order cannot be completed by hand', O::transition($cod, 'completed', $actor) !== null && $row($cod)['status'] === 'shipped');
check('the courier delivery completes the COD order and marks it paid', G::recordEvent($event('CODT1', 'Successful Delivery', 'e1')) === 'recorded' && $row($cod)['status'] === 'completed' && $row($cod)['payment_status'] === 'paid');
check('a repeated delivery update changes nothing more', G::recordEvent($event('CODT1', 'Successful Delivery', 'e2')) === 'recorded' && $row($cod)['status'] === 'completed');

$cod4 = $mk('cod', 'unpaid', 'shipped', 'CODT4');
G::recordEvent($event('CODT4', 'Completed', 'e6'));
G::recordEvent($event('CODT4', 'Successful Delivery', 'e7'));
check('either delivery status completes the COD order, whichever arrives first', $row($cod4)['status'] === 'completed' && $row($cod4)['payment_status'] === 'paid');

$cod2 = $mk('cod', 'unpaid', 'processing', 'CODT2');
G::recordEvent($event('CODT2', 'Successful Delivery', 'e3'));
check('a COD order still in Processing goes through Shipped to Completed', $row($cod2)['status'] === 'completed' && $row($cod2)['payment_status'] === 'paid');

$cod3 = $mk('cod', 'unpaid', 'shipped', 'CODT3');
G::recordEvent($event('CODT3', 'On Vehicle for Delivery', 'e4'));
check('an intermediate courier status does not complete the order', $row($cod3)['status'] === 'shipped');

$pre = $mk('billplz', 'paid', 'shipped', 'PRET1');
check('a prepaid order can still be completed by hand', O::transition($pre, 'completed', $actor) === null && $row($pre)['status'] === 'completed');
$preTo = $mk('billplz', 'paid', 'shipped', 'PRET2');
G::recordEvent($event('PRET2', 'Successful Delivery', 'e5'));
check('a prepaid order is not completed by the courier (left to staff)', $row($preTo)['status'] === 'shipped');

// tidy up (a courier booking must go before its order)
DB::query('DELETE FROM shipments');
DB::query("DELETE FROM orders WHERE order_no LIKE 'C-%'");
