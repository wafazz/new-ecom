<?php
// DB-backed shipment checks (eligibility, webhook events, cancel guard). Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\User;
use App\Services\NinjaVanSettings as N;
use App\Services\OrderService as O;
use App\Services\Shipping\NinjaVanGateway as G;
use App\Services\ShipmentService as S;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'ninjavan.%'");
DB::query('TRUNCATE TABLE shipment_events');
putenv('APP_KEY=shipment-db-test-key-0123456789abcdef012');
$admin = User::create('admin', 'Admin', 'a@sh.test', 'Passw0rd!x');
$mk = function (string $status = 'processing', string $method = 'cod', string $pay = 'unpaid') {
    DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, total, payment_method, payment_status, status)
               VALUES (?, 'Buyer One', 'b@x.test', '0123456789', '1 Jalan', 'KL', 'Kuala Lumpur', '50000', 100, 100, ?, ?, ?)", ['ORD-' . strtoupper(bin2hex(random_bytes(5))), $method, $pay, $status]);
    return O::find((int) DB::connection()->lastInsertId());
};
$o = $mk();
check('blocked while NinjaVan is not configured', str_contains((string) G::blocker($o), 'not configured'));
N::save(['mode' => 'sandbox', 'country' => 'MY', 'client_id' => 'nv-client-1', 'client_secret' => 'nv-secret-123456'] + array_fill_keys(N::SENDER, ''), $admin);
check('blocked while the pickup address is missing', str_contains((string) G::blocker($o), 'pickup address'));
N::save(['mode' => 'sandbox', 'country' => 'MY', 'client_id' => 'nv-client-1', 'client_secret' => ''] + ['sender_name' => 'Lure Shop', 'sender_phone' => '012-3456789', 'sender_email' => 's@l.test', 'sender_address1' => '9 Jalan', 'sender_address2' => '', 'sender_postcode' => '50000', 'sender_city' => 'KL', 'sender_state' => 'Kuala Lumpur'], $admin);
check('processing COD order is eligible', G::blocker($o) === null);
check('pending order not eligible', str_contains((string) G::blocker($mk('pending')), 'Processing'));
check('shipped/cancelled/completed orders not eligible', G::blocker($mk('shipped')) !== null && G::blocker($mk('cancelled')) !== null && G::blocker($mk('completed')) !== null);
check('unpaid bank transfer / Billplz not eligible until paid', str_contains((string) G::blocker($mk('processing', 'bank_transfer')), 'Payment') && str_contains((string) G::blocker($mk('processing', 'billplz')), 'Payment'));
check('paid online order is eligible', G::blocker($mk('processing', 'billplz', 'paid')) === null);
DB::query("UPDATE orders SET ship_country = 'SG' WHERE id = ?", [$o['id']]);
check('non-MY destination blocked (Malaysia only for now)', str_contains((string) G::blocker(O::find((int) $o['id'])), 'Malaysia'));
DB::query("UPDATE orders SET ship_country = 'MY' WHERE id = ?", [$o['id']]);
// The NinjaVan account (sandbox is always /sg) may be Singapore; only the destination must be Malaysia.
DB::query("INSERT INTO settings (`key`, `value`) VALUES ('ninjavan.country', 'SG') ON DUPLICATE KEY UPDATE `value` = 'SG'");
check('a Singapore account can still ship to Malaysia addresses', !str_contains((string) G::blocker(O::find((int) $o['id'])), 'Malaysia'));
DB::query("INSERT INTO settings (`key`, `value`) VALUES ('ninjavan.country', 'MY') ON DUPLICATE KEY UPDATE `value` = 'MY'");

$ins = fn(int $oid, string $tn) => DB::query("INSERT INTO shipments (order_id, tracking_number, service_level, weight_kg, delivery_date, delivery_timeslot) VALUES (?, ?, 'Standard', 1, CURDATE(), '09:00-18:00')", [$oid, $tn]);
$ins((int) $o['id'], 'TRK000000001');
check('active shipment is found and now blocks another', S::active((int) $o['id'])['tracking_number'] === 'TRK000000001' && str_contains((string) G::blocker(O::find((int) $o['id'])), 'already'));
$second = false;
try { $ins((int) $o['id'], 'TRK000000002'); } catch (PDOException $e) { $second = true; }
check('database refuses a second ACTIVE shipment for the same order', $second);
$dupTn = false;
try { $ins((int) $mk()['id'], 'TRK000000001'); } catch (PDOException $e) { $dupTn = true; }
check('tracking numbers are unique', $dupTn);

check('cancelling the order is refused while a shipment is live', O::transition((int) $o['id'], 'cancelled', $admin) === 'Cancel the NinjaVan shipment first, then cancel the order.' && O::find((int) $o['id'])['status'] === 'processing');

// webhook events
$ev = fn(array $p) => G::recordEvent($p + ['tracking_id' => 'TRK000000001']);
check('unknown tracking id ignored', G::recordEvent(['tracking_id' => 'NOPE', 'status' => 'Completed']) === 'unknown' && G::recordEvent(['status' => 'x']) === 'unknown' && G::recordEvent(['tracking_id' => 'TRK000000001']) === 'unknown');
check('first event recorded, status updated', $ev(['id' => 'e1', 'status' => 'Pending Pickup', 'timestamp' => '2026-10-22T10:00:00+08:00']) === 'recorded' && S::active((int) $o['id'])['status'] === 'Pending Pickup');
check('retried delivery of the same event is a duplicate (no double row)', $ev(['id' => 'e1', 'status' => 'Pending Pickup', 'timestamp' => '2026-10-22T10:00:00+08:00']) === 'duplicate' && (int) DB::query('SELECT COUNT(*) FROM shipment_events')->fetchColumn() === 1);
$ev(['id' => 'e3', 'status' => 'On Vehicle for Delivery', 'timestamp' => '2026-10-22T15:00:00+08:00']);
$ev(['id' => 'e2', 'status' => 'Successful Pickup', 'timestamp' => '2026-10-22T12:00:00+08:00']);
check('out-of-order event is stored but does not overwrite the newer status', S::active((int) $o['id'])['status'] === 'On Vehicle for Delivery' && count(S::events((int) DB::query('SELECT id FROM shipments LIMIT 1')->fetchColumn())) === 3);
check('notable events reach the order history, routine ones do not', (int) DB::query("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND event = 'shipment_update'", [$o['id']])->fetchColumn() === 2);
check('hostile payload values are stored safely (length-capped, no crash)', $ev(['id' => ['x'], 'status' => str_repeat('A', 500), 'comments' => str_repeat('B', 999), 'timestamp' => 'not a date']) === 'recorded' && (int) DB::query('SELECT MAX(CHAR_LENGTH(status)) FROM shipment_events')->fetchColumn() <= 60);
$ev(['id' => 'e9', 'status' => 'Cancelled', 'timestamp' => date('c', time() + 3600)]);
check('courier-side cancellation frees the order (shipment inactive, history note)', S::active((int) $o['id']) === null && str_contains((string) DB::query("SELECT GROUP_CONCAT(note) FROM order_events WHERE order_id = ? AND event = 'shipment_cancelled'", [$o['id']])->fetchColumn(), 'cancelled by the courier'));
check('after a cancelled shipment the order is eligible again and may be cancelled', G::blocker(O::find((int) $o['id'])) === null && O::transition((int) $o['id'], 'cancelled', $admin) === null);
check('history keeps the cancelled shipment', count(S::history((int) $o['id'])) === 1 && S::history((int) $o['id'])[0]['active'] === null);
check('activeAmong lists no cancelled booking and ignores unknown orders', S::activeAmong([(int) $o['id'], 999999]) === [] && S::activeAmong([]) === []);
// AWB printed marker: set on the first print of the active booking, never moved afterwards.
DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES ('P-PRINT-1', 'Ann', 'ann@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 10, 0, 10, 'cod', 'unpaid', 'processing')");
$pid = (int) DB::connection()->lastInsertId();
check('no printed mark before any booking', S::markAwbPrinted($pid) === false);
DB::query("INSERT INTO shipments (order_id, tracking_number, service_level, weight_kg, delivery_date, delivery_timeslot, active) VALUES (?, 'PRN1', 'Standard', 1, '2026-11-01', '09:00-18:00', 1)", [$pid]);
check('first print marks the active booking', S::markAwbPrinted($pid) === true && S::active($pid)['awb_printed_at'] !== null);
$first = S::active($pid)['awb_printed_at'];
sleep(1);
S::markAwbPrinted($pid);
check('a second print keeps the first time', S::active($pid)['awb_printed_at'] === $first);
check('activeAmong carries the printed time for the list badge', !empty(S::activeAmong([$pid])[$pid]['awb_printed_at']));
// Bulk printing: one UPDATE marks the active bookings of several orders; only live bookings count.
DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES ('P-BULK-1', 'Ann', 'ann@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 10, 0, 10, 'cod', 'unpaid', 'processing'), ('P-BULK-2', 'Ben', 'ben@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 10, 0, 10, 'cod', 'unpaid', 'processing')");
$b1 = (int) DB::query("SELECT id FROM orders WHERE order_no = 'P-BULK-1'")->fetchColumn();
$b2 = (int) DB::query("SELECT id FROM orders WHERE order_no = 'P-BULK-2'")->fetchColumn();
DB::query("INSERT INTO shipments (order_id, tracking_number, service_level, weight_kg, delivery_date, delivery_timeslot, active) VALUES (?, 'BLK1', 'Standard', 1, '2026-11-01', '09:00-18:00', 1), (?, 'BLK2', 'Standard', 1, '2026-11-01', '09:00-18:00', 1)", [$b1, $b2]);
check('findAmong returns the orders keyed by id', count(\App\Services\OrderService::findAmong([$b1, $b2, 999999])) === 2 && \App\Services\OrderService::findAmong([]) === []);
check('bulk print marks every live booking in one step', S::markAwbPrintedAmong([$b1, $b2, 999999]) === 2 && S::active($b1)['awb_printed_at'] !== null && S::active($b2)['awb_printed_at'] !== null);
check('bulk print keeps the first printed time (nothing new to mark the second time)', S::markAwbPrintedAmong([$b1]) === 0 && S::markAwbPrintedAmong([]) === 0);

