<?php
// Email (Resend): settings, sending (stubbed), sign-up confirmation links, order email, announcements in batches. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Core\SecretBox;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailService as E;
use App\Services\EmailSettings as S;
use App\Services\MailService as M;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
putenv('APP_KEY=email-db-test-key-0123456789abcdef0123');
DB::query("DELETE FROM settings WHERE `key` LIKE 'email.%'");
$admin = User::create('admin', 'Em Admin', 'em@example.test', 'Passw0rd!x');

// Rules and storage.
$all = function (array $over = []): array {
    $base = ['verify' => ['on' => '1', 'subject' => 'Sahkan', 'body' => 'Hai {name} {verify_url}'], 'order' => ['on' => '1', 'subject' => 'Pesanan {order_no}', 'body' => S::defaults()['order']['body']]];
    return array_replace_recursive($base, $over);
};
check('turning email on without a key is refused', S::save(['enabled' => '1', 'key' => '', 'from_name' => '', 'from_email' => '', 'reply_to' => '', 'tpl' => $all()], $admin) !== [] && !S::enabled());
check('a key that is not a Resend key (re_…) is refused', S::save(['enabled' => '0', 'key' => 'abc', 'from_name' => '', 'from_email' => '', 'reply_to' => '', 'tpl' => $all()], $admin) !== []);
check('a bad sender address is refused', S::save(['enabled' => '0', 'key' => '', 'from_name' => '', 'from_email' => 'not-an-email', 'reply_to' => '', 'tpl' => $all()], $admin) !== []);
check('an empty template is refused', S::save(['enabled' => '0', 'key' => '', 'from_name' => '', 'from_email' => '', 'reply_to' => '', 'tpl' => $all(['order' => ['body' => '']])], $admin) !== []);
check('the key is saved encrypted and never shown in full', S::save(['enabled' => '0', 'key' => 're_SECRETKEY9876', 'from_name' => 'Lure Shop', 'from_email' => 'shop@example.test', 'reply_to' => '', 'tpl' => $all()], $admin) === [] && Setting::get('email.key') !== 're_SECRETKEY9876' && S::apiKey() === 're_SECRETKEY9876' && !str_contains(json_encode(S::overview()), 're_SECRETKEY9876') && str_ends_with((string) S::overview()['key_hint'], '9876'));
check('the defaults cover both automatic emails', array_keys(S::defaults()) === ['verify', 'order']);
check('HTML from text is escaped (no markup from a message gets through)', str_contains(M::html("<script>x</script>\nline"), '&lt;script&gt;') && !str_contains(M::html('<b>x</b>'), '<b>') && str_contains(M::html("a\nb"), '<br'));

// Sending through a stub: the request shape and the log.
$calls = [];
M::setTransport(function (string $m, string $path, string $key, ?string $body, array $headers) use (&$calls): array {
    $calls[] = ['m' => $m, 'path' => $path, 'key' => $key, 'body' => $body, 'headers' => $headers];
    if ($path === '/emails/batch') {
        $n = count(json_decode((string) $body, true));
        return ['code' => 200, 'json' => ['data' => array_map(fn($i) => ['id' => "b{$i}"], range(0, $n - 1))]];
    }
    return ['code' => 200, 'json' => ['id' => 're-1']];
});
check('sending is off until the email is switched on', E::ready() === false && M::send('a@x.test', 's', 't', 'test')['ok'] === false && (int) DB::query('SELECT COUNT(*) FROM email_log')->fetchColumn() === 0);
S::save(['enabled' => '1', 'key' => '', 'from_name' => 'Lure Shop', 'from_email' => 'shop@example.test', 'reply_to' => 'help@example.test', 'tpl' => $all()], $admin);
check('switched on: the email goes to Resend with the key, sender and reply-to', E::ready() && M::send('buyer@x.test', 'Hai', 'Teks', 'test')['ok'] === true);
$body = json_decode((string) $calls[0]['body'], true);
check('the request is POST /emails with Bearer key, from, to and text', $calls[0]['m'] === 'POST' && $calls[0]['path'] === '/emails' && $calls[0]['key'] === 're_SECRETKEY9876' && $body['from'] === 'Lure Shop <shop@example.test>' && $body['to'] === ['buyer@x.test'] && $body['text'] === 'Teks' && $body['reply_to'] === 'help@example.test');
$log = DB::query('SELECT * FROM email_log ORDER BY id DESC LIMIT 1')->fetch();
check('the sent email is logged with its Resend id', $log && (int) $log['ok'] === 1 && $log['resend_id'] === 're-1' && $log['kind'] === 'test');

// Sign-up confirmation: one-time, expiring, resend throttled.
$member = User::create('member', 'Siti Aminah', 'siti@example.test', 'Passw0rd!x');
DB::query('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$member]);
check('a new member is not confirmed yet', DB::query('SELECT email_verified_at FROM users WHERE id = ?', [$member])->fetchColumn() === null);
check('the confirmation link is emailed', E::sendVerification($member, 'siti@example.test', 'Siti Aminah') === 'sent');
$sentBody = json_decode((string) end($calls)['body'], true);
preg_match('#/verify-email/([a-f0-9]{64})#', (string) $sentBody['text'], $tm);
$token = $tm[1] ?? '';
check('the email holds a 64-character link token', strlen($token) === 64);
check('the link is stored hashed, never as the token itself', DB::query('SELECT COUNT(*) FROM email_verifications WHERE token_hash = ?', [$token])->fetchColumn() === 0 && DB::query('SELECT COUNT(*) FROM email_verifications WHERE token_hash = ?', [hash('sha256', $token)])->fetchColumn() === 1);
check('a second request inside two minutes is refused', E::resendVerification($member) === 'wait');
check('a bad token is refused', E::confirm('nope') === null && E::confirm(str_repeat('0', 64)) === null);
check('the right link confirms the address', E::confirm($token) === $member && DB::query('SELECT email_verified_at FROM users WHERE id = ?', [$member])->fetchColumn() !== null);
check('a link works only once', E::confirm($token) === null);
check('an already confirmed member gets "already confirmed"', E::resendVerification($member) === 'verified');

$member2 = User::create('member', 'Expired Link', 'exp@example.test', 'Passw0rd!x');
E::sendVerification($member2, 'exp@example.test', 'Expired Link');
DB::query('UPDATE email_verifications SET expires_at = NOW() - INTERVAL 1 HOUR WHERE user_id = ?', [$member2]);
$t2 = json_decode((string) end($calls)['body'], true)['text'];
preg_match('#/verify-email/([a-f0-9]{64})#', (string) $t2, $m2);
check('an expired link is refused', E::confirm($m2[1] ?? '') === null);

// Order created email: to the customer, with the items and the tracking link.
$oid = (int) (function () { DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES ('EM-1', 'Ali', 'ali@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 54, 0, 54, 'cod', 'unpaid', 'pending')"); return (int) DB::connection()->lastInsertId(); })();
DB::query("INSERT INTO order_items (order_id, product_id, product_name, variant_label, unit_price, retail_price, base_price, company_base_price, qty, line_total) VALUES (?, NULL, 'Lure Silicone Shrimp', 'Pink', 18, 18, 10, 12, 3, 54)", [$oid]);
E::orderCreated($oid, 'https://shop.example.test/order/EM-1/abc');
$oBody = json_decode((string) end($calls)['body'], true);
check('the order email goes to the customer with items, total and tracking link', $oBody['to'] === ['ali@example.test'] && str_contains($oBody['subject'], 'EM-1') && str_contains($oBody['text'], '3 x Lure Silicone Shrimp (Pink)') && str_contains($oBody['text'], 'RM54.00') && str_contains($oBody['text'], 'https://shop.example.test/order/EM-1/abc'));
DB::query("UPDATE orders SET customer_email = 'not-an-email' WHERE id = ?", [$oid]);
$before = count($calls);
E::orderCreated($oid, 'x');
check('an invalid customer email is skipped', count($calls) === $before);

// Announcements: members and customers, de-duplicated, sent in batches of 100.
$audience = fn(string $a) => count(E::recipients($a));
DB::query("UPDATE users SET status = 'active' WHERE role = 'member'");
$before = $audience('members');
check('members only counts active members with an email', $before >= 2);
for ($i = 0; $i < 120; $i++) {
    DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES (?, 'Pelanggan', ?, '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 10, 0, 10, 'cod', 'unpaid', 'completed')", ['BULK-' . $i, "bulk{$i}@example.test"]);
}
DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES ('DUP-1', 'Dup', 'BULK0@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 10, 0, 10, 'cod', 'unpaid', 'completed')");
check('customers include guests and count each valid email once (case-insensitive)', $audience('customers') === count(array_unique(array_map('strtolower', array_filter(array_column(DB::query("SELECT customer_email FROM orders WHERE customer_email <> ''")->fetchAll(), 'customer_email'), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)))));
check('everyone is members plus customers, each person once', $audience('everyone') === count(array_unique(array_map(fn($r) => strtolower($r['email']), array_merge(E::recipients('members'), E::recipients('customers'))))));
$calls = [];
$r = E::announce('Promo Raya', 'Hai {name}, promosi untuk {shop}.', 'customers', $admin);
check('an announcement to 120+ people goes out in batches of 100', is_array($r) && $r['recipients'] === $audience('customers') && $r['sent'] === $r['recipients'] && count(array_filter($calls, fn($c) => $c['path'] === '/emails/batch')) === (int) ceil($r['recipients'] / 100));
check('each batch carries its own idempotency key', $calls[0]['headers'] !== [] && str_starts_with($calls[0]['headers'][0], 'Idempotency-Key: campaign-'));
check('the campaign is recorded with its counts', (int) DB::query('SELECT sent FROM email_campaigns WHERE id = ?', [$r['id']])->fetchColumn() === $r['sent']);
check('an unknown audience is refused', is_string(E::announce('x', 'y', 'nobody', $admin)));

// tidy up: other tests never reach Resend
M::setTransport(null);
DB::query("DELETE FROM settings WHERE `key` LIKE 'email.%'");
DB::query('DELETE FROM email_log');
DB::query('DELETE FROM email_campaigns');
