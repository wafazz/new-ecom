<?php
// WhatsApp (onsend.io): templates, phone numbers, on/off rules, encrypted token, status messages. The network is stubbed. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderService as O;
use App\Services\WhatsAppService as W;
use App\Services\WhatsAppSettings as S;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
// All six status templates (the settings form always sends them all); $over changes some of them.
$allTpl = function (array $over = []): array {
    $t = [];
    foreach (array_keys(S::STATUSES) as $s) {
        $t[$s] = ['on' => '1', 'text' => S::defaults()[$s]];
    }
    return array_replace_recursive($t, $over);
};
putenv('APP_KEY=whatsapp-db-test-key-0123456789abcdef0123');
DB::query('DELETE FROM whatsapp_log');
DB::query("DELETE FROM settings WHERE `key` LIKE 'wa.%'");
$admin = User::create('admin', 'WA Admin', 'wa@example.test', 'Passw0rd!x');

// Pure rules first.
check('placeholders are filled and unknown ones are left alone', W::render('Hai {name}, {order_no} RM{total} {nope}', ['name' => 'Ali', 'order_no' => 'ORD-1', 'total' => '54.00']) === 'Hai Ali, ORD-1 RM54.00 {nope}');
check('Malaysian numbers become 60… digits', W::phone('012-3456789') === '60123456789' && W::phone('+60 12 345 6789') === '60123456789' && W::phone('60123456789') === '60123456789');
check('an invalid number gives nothing', W::phone('abc') === null && W::phone('') === null);
check('every status has a default template', count(S::defaults()) === count(S::STATUSES));

// The token is stored encrypted, and only enabling needs one.
$errs = S::save(['enabled' => '1', 'token' => '', 'tpl' => $allTpl()], $admin);
check('turning WhatsApp on without a token is refused', $errs !== [] && S::enabled() === false);
$errs = S::save(['enabled' => '0', 'token' => 'abc123SECRET', 'tpl' => $allTpl()], $admin);
check('the token is saved encrypted, never as plain text', $errs === [] && Setting::get('wa.token') !== 'abc123SECRET' && S::token() === 'abc123SECRET');
check('the settings view shows only the last four characters', str_ends_with((string) S::overview()['token_hint'], 'CRET') && !str_contains(json_encode(S::overview()), 'abc123SECRET'));
check('an empty template is refused', S::save(['enabled' => '0', 'token' => '', 'tpl' => $allTpl(['shipped' => ['on' => '1', 'text' => '']])], $admin) !== []);
check('a long template is accepted (no length limit)', S::save(['enabled' => '0', 'token' => '', 'tpl' => $allTpl(['shipped' => ['text' => str_repeat('x', 3000)]])], $admin) === [] && strlen(S::template('shipped')['text']) === 3000);
check('the ordered items placeholder is listed for HQ', in_array('{ordered_item}', S::VARIABLES, true));

// Sending: stubbed network.
$calls = [];
W::setTransport(function (string $m, string $url, string $tok, ?string $body) use (&$calls): array {
    $calls[] = ['m' => $m, 'url' => $url, 'tok' => $tok, 'body' => $body];
    return ['code' => 200, 'json' => ['success' => true, 'message' => 'The message has been sent successfully.', 'message_id' => 77]];
});
$order = function (string $phone, string $status): int {
    DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES (?, 'Ali Bin Abu', 'ali@example.test', ?, '1 St', 'KL', 'Selangor', '47300', 'MY', 54, 0, 54, 'cod', 'unpaid', ?)", ['WA-' . bin2hex(random_bytes(3)), $phone, $status]);
    return (int) DB::connection()->lastInsertId();
};

$o1 = $order('012-3456789', 'pending');
W::notifyStatus($o1, 'pending');
check('disabled: nothing is sent and nothing is logged', $calls === [] && (int) DB::query('SELECT COUNT(*) FROM whatsapp_log')->fetchColumn() === 0);

S::save(['enabled' => '1', 'token' => '', 'tpl' => $allTpl()], $admin);
W::notifyStatus($o1, 'pending');
check('enabled: the customer gets the status message through the API', count($calls) === 1 && $calls[0]['m'] === 'POST' && $calls[0]['url'] === 'https://onsend.io/api/v1/send' && $calls[0]['tok'] === 'abc123SECRET');
$body = json_decode((string) $calls[0]['body'], true);
check('the message goes to 60… with the order number and total filled in', $body['phone_number'] === '60123456789' && $body['type'] === 'text' && str_contains($body['message'], 'WA-') && str_contains($body['message'], 'RM54.00'), json_encode($body));
$log = DB::query('SELECT * FROM whatsapp_log ORDER BY id DESC LIMIT 1')->fetch();
check('the send is logged with the status, the number and the message id', $log && $log['status_key'] === 'pending' && (int) $log['ok'] === 1 && (int) $log['message_id'] === 77 && $log['phone'] === '60123456789');

$calls = [];
S::save(['enabled' => '1', 'token' => '', 'tpl' => $allTpl(['shipped' => ['on' => '', 'text' => 'Shipped {order_no}']])], $admin);
W::notifyStatus($o1, 'shipped');
check('a status switched off in its template is not sent', $calls === []);

$calls = [];
S::save(['enabled' => '1', 'token' => '', 'tpl' => $allTpl(['shipped' => ['on' => '1', 'text' => 'Parcel {order_no} on the way, tracking {tracking}']])], $admin);
W::notifyStatus($o1, 'shipped');
check('a status switched on sends its own template', $calls !== [] && str_contains((string) json_decode((string) $calls[0]['body'], true)['message'], 'on the way'));

$bad = $order('123', 'processing');
W::notifyStatus($bad, 'processing');
$badLog = DB::query("SELECT * FROM whatsapp_log WHERE order_id = ? ORDER BY id DESC LIMIT 1", [$bad])->fetch();
check('an invalid customer number is logged, nothing is sent', $badLog && (int) $badLog['ok'] === 0 && str_contains((string) $badLog['error'], 'not valid'));

W::setTransport(fn() => ['code' => 0, 'json' => null]);
W::notifyStatus($o1, 'completed');
$netLog = DB::query("SELECT * FROM whatsapp_log WHERE order_id = ? AND status_key = 'completed'", [$o1])->fetch();
check('a network failure is logged and does not throw', $netLog && (int) $netLog['ok'] === 0 && $netLog['error'] === 'Network problem');

// Status changes send the message (after the change is saved).
W::setTransport(function (string $m, string $url, string $tok, ?string $body): array {
    return ['code' => 200, 'json' => ['success' => true, 'message_id' => 9]];
});
$o2 = $order('0123456789', 'pending');
$transitioned = O::transition($o2, 'processing', null);
\App\Services\Queue::run(); // the worker sends the queued status message
check('a status change sends that status message', $transitioned === null &&(int) DB::query("SELECT COUNT(*) FROM whatsapp_log WHERE order_id = ? AND status_key = 'processing'", [$o2])->fetchColumn() === 1);
check('a refused status change sends nothing', O::transition($o2, 'returned', null) !== null && (int) DB::query("SELECT COUNT(*) FROM whatsapp_log WHERE order_id = ? AND status_key = 'returned'", [$o2])->fetchColumn() === 0);

W::setTransport(null);

// {ordered_item}: every product on its own line, with quantity and variant.
DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES ('WA-ITEMS', 'Ali', 'ali@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 90, 0, 90, 'cod', 'unpaid', 'pending')");
$oi = (int) DB::connection()->lastInsertId();
DB::query("INSERT INTO order_items (order_id, product_id, product_name, variant_label, unit_price, retail_price, base_price, company_base_price, qty, line_total) VALUES (?, NULL, 'Lure Silicone Shrimp', 'Pink / 3in', 18, 18, 10, 12, 2, 36), (?, NULL, 'Hook Pack 2/0', NULL, 9.9, 9.9, 5, 6, 1, 9.9)", [$oi, $oi]);
check('ordered items are listed one product per line', W::orderedItems($oi) === "2 x Lure Silicone Shrimp (Pink / 3in)\n1 x Hook Pack 2/0");
$sentBody = '';
W::setTransport(function (string $m, string $url, string $tok, ?string $body) use (&$sentBody): array { $sentBody = (string) $body; return ['code' => 200, 'json' => ['success' => true, 'message_id' => 5]]; });
S::save(['enabled' => '1', 'token' => '', 'tpl' => $allTpl(['pending' => ['text' => 'Items: {ordered_item} / {order_no}']])], $admin);
W::notifyStatus($oi, 'pending');
$sentItems = DB::query("SELECT * FROM whatsapp_log WHERE order_id = ? ORDER BY id DESC LIMIT 1", [$oi])->fetch();
check('the message fills {ordered_item} in with the products', $sentItems && (int) $sentItems['ok'] === 1 && str_contains((string) json_decode($sentBody, true)['message'], "Items: 2 x Lure Silicone Shrimp (Pink / 3in)\n1 x Hook Pack 2/0 / WA-ITEMS"));
W::setTransport(null);

// tidy up: other tests must never reach onsend.io, so WhatsApp goes back to off and empty
DB::query("DELETE FROM settings WHERE `key` LIKE 'wa.%'");
DB::query('DELETE FROM whatsapp_log');
