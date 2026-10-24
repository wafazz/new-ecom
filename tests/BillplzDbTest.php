<?php
// DB-backed Billplz settings checks. Runs only when DB_NAME ends in `_test`.
use App\Core\Database as DB;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BillplzSettings as B;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'billplz.%'");
putenv('APP_KEY=billplz-db-test-key-0123456789abcdef0123');
$admin = User::create('admin', 'Admin', 'a@bp.test', 'Passw0rd!x');
$good = ['mode' => 'sandbox', 'collection_id' => 'abcd1234', 'api_key' => '73eb57f0-7d4e-42b9-a544-aeac6e4b0f81', 'x_signature_key' => 'S-1234567890abcdef'];

check('empty form: all three fields required', array_keys(B::validate(['mode' => 'sandbox', 'collection_id' => '', 'api_key' => '', 'x_signature_key' => ''])) === ['collection_id', 'api_key', 'x_signature_key']);
check('bad mode / collection / spaces in key rejected', array_keys(B::validate(['mode' => 'evil', 'collection_id' => '../x', 'api_key' => 'has space 12345', 'x_signature_key' => 'short'])) === ['mode', 'collection_id', 'api_key', 'x_signature_key']);
check('valid input passes', B::validate($good) === []);

DB::query("DELETE FROM settings WHERE `key` LIKE 'billplz.%'"); DB::query('DELETE FROM audit_logs');
$changed = B::save($good, $admin);
check('save stores collection + both secrets', in_array('collection_id', $changed, true) && in_array('api_key', $changed, true) && in_array('x_signature_key', $changed, true));
$raw = Setting::get('billplz.api_key') . Setting::get('billplz.x_signature_key');
check('secrets are encrypted at rest', !str_contains($raw, '73eb57f0') && !str_contains($raw, 'S-1234567890') && str_starts_with(Setting::get('billplz.api_key'), 'enc:v1:'));
$o = B::overview();
check('overview exposes hints only, never the secrets', $o['api_key_hint'] === '••••0f81' && !str_contains(json_encode($o), '73eb57f0') && !str_contains(json_encode($o), 'S-1234567890'));
$c = B::credentials();
check('credentials() decrypts for server-side use', $c !== null && $c['api_key'] === $good['api_key'] && $c['base_url'] === 'https://www.billplz-sandbox.com/api');

check('blank secrets keep the stored ones', B::validate(['api_key' => '', 'x_signature_key' => ''] + $good) === [] && B::save(['api_key' => '', 'x_signature_key' => '', 'collection_id' => 'newcol99'] + $good, $admin) === ['collection_id'] && B::credentials()['api_key'] === $good['api_key']);
check('switching mode without new keys is refused', array_keys(B::validate(['mode' => 'production', 'api_key' => '', 'x_signature_key' => ''] + $good)) === ['api_key', 'x_signature_key']);
check('switching mode with new keys is accepted', B::validate(['mode' => 'production', 'api_key' => 'prod-key-123456', 'x_signature_key' => 'prod-sig-123456'] + $good) === []);

Setting::set('billplz.verified', 'ok');
B::save(['api_key' => 'rotated-key-9999', 'x_signature_key' => ''] + $good, $admin);
check('changing a key clears the last test result', Setting::get('billplz.verified') === '');

$log = json_encode(AuditService::recent('billplz.', 20));
check('audit trail records who/what but never a secret', str_contains($log, 'api_key') && !str_contains($log, 'rotated-key') && !str_contains($log, '73eb57f0') && !str_contains($log, 'S-1234567890'));

putenv('APP_KEY=a-different-key-0123456789abcdef0123456789');
check('after APP_KEY change: flagged unreadable, credentials() null, no crash', B::overview()['unreadable'] === true && B::credentials() === null && isset(B::validate(['api_key' => '', 'x_signature_key' => ''] + $good)['api_key']));

// ---- payments: recordPayment / checkout visibility --------------------------------------------
use App\Services\OrderService as O;
use App\Services\Payment\BillplzGateway as G;
use App\Services\Payment\Payments;

putenv('APP_KEY=billplz-db-test-key-0123456789abcdef0123');
DB::query("DELETE FROM settings WHERE `key` LIKE 'billplz.%'");
check('billplz hidden at checkout while unconfigured', !isset(Payments::checkoutMethods()['billplz']) && isset(Payments::checkoutMethods()['cod']));
B::save($good, $admin);
check('still hidden after saving (needs a passing test)', !isset(Payments::checkoutMethods()['billplz']));
Setting::set('billplz.verified', 'ok');
check('visible once the test passed', isset(Payments::checkoutMethods()['billplz']));
B::save(['api_key' => 'rotated-key-777777', 'x_signature_key' => ''] + $good, $admin);
check('credential change hides it again until re-tested', !isset(Payments::checkoutMethods()['billplz']));

$mk = function (string $status) use ($admin): int {
    DB::query("INSERT INTO orders (order_no, user_id, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, shipping_fee, total, payment_method, status, billplz_bill_id)
               VALUES (?, NULL, 'N', 'n@x.test', '0123456789', 'l', 'c', 'Johor', '50000', 100, 0, 100, 'billplz', ?, ?)", ['ORD-' . strtoupper(bin2hex(random_bytes(4))), $status, 'bill' . random_int(1000, 9999)]);
    return (int) DB::connection()->lastInsertId();
};
$a = $mk('pending');
check('first verified payment marks the order paid', O::recordPayment($a, 'Paid via Billplz') === 'paid' && O::find($a)['payment_status'] === 'paid');
check('callback + return both reporting is idempotent', O::recordPayment($a, 'again') === 'already' && (int) DB::query("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND event = 'paid'", [$a])->fetchColumn() === 1);
$c = $mk('cancelled');
check('payment after cancellation is recorded and flagged for refund', O::recordPayment($c, 'Paid via Billplz') === 'late' && O::find($c)['payment_status'] === 'paid' && str_contains((string) DB::query("SELECT note FROM order_events WHERE order_id = ? AND event = 'paid_after_cancel'", [$c])->fetchColumn(), 'REFUND NEEDED'));
check('unknown order id handled', O::recordPayment(999999, 'x') === 'missing');
check('orderByBill finds the order, unknown bill returns null', G::orderByBill(O::find($a)['billplz_bill_id'])['id'] == $a && G::orderByBill('nope') === null);
DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, total, payment_method, billplz_bill_id) VALUES ('ORD-DUPLICATE1', 'N', 'n@x.test', '0123456789', 'l', 'c', 'Johor', '50000', 1, 1, 'billplz', 'dupbill')");
$dupe = false;
try { DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, total, payment_method, billplz_bill_id) VALUES ('ORD-DUPLICATE2', 'N', 'n@x.test', '0123456789', 'l', 'c', 'Johor', '50000', 1, 1, 'billplz', 'dupbill')"); } catch (PDOException $e) { $dupe = true; }
check('one bill id can only belong to one order (UNIQUE)', $dupe);
