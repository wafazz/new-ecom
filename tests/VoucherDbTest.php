<?php
// Discount codes: rules, checkout totals, one-use limits, release on cancel, and the affiliate split. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\AffiliateService;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\EmailSettings as S;
use App\Services\MailService as M;
use App\Services\OrderService;
use App\Services\UnsubscribeService;
use App\Services\VoucherService as V;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
putenv('APP_KEY=voucher-db-test-key-0123456789abcdef0123'); // the email API key is stored encrypted
DB::query("DELETE FROM settings WHERE `key` LIKE 'shipping%'");
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Vee Admin', 'vee@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();
$mug = Product::insert(['name' => 'Mug', 'slug' => 'mug', 'sku' => 'MUG-V', 'category_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '30', 'stock' => 50, 'status' => 'active']);
$cart = function (int $qty) use ($mug): int {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', random_bytes(8))]);
    $id = (int) DB::connection()->lastInsertId();
    DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty) VALUES (?,?,?,?)', [$id, $mug, null, $qty]);
    return $id;
};
$contact = ['name' => 'Buyer', 'email' => 'buyer@example.test', 'phone' => '0123', 'line1' => '1 Rd', 'line2' => '', 'city' => 'KL', 'state' => 'Kuala Lumpur', 'postcode' => '50000', 'notes' => ''];
$uses = fn(string $code): int => (int) DB::query('SELECT used_count FROM vouchers WHERE code = ?', [$code])->fetchColumn();

// Creating codes: the rules and the messages.
$made = fn(array $in) => V::create($in, $actor);
check('a new percent code is saved in upper case', $made(['code' => 'raya10', 'type' => 'percent', 'value' => '10'])['errors'] === [] && V::find('RAYA10') !== null);
check('a fixed code is saved', $made(['code' => 'SAVE5', 'type' => 'fixed', 'value' => '5'])['errors'] === []);
check('a code with a minimum spend is saved', $made(['code' => 'BIG50', 'type' => 'fixed', 'value' => '50', 'min_subtotal' => '100'])['errors'] === []);
check('a code limited to one use is saved', $made(['code' => 'ONCE', 'type' => 'percent', 'value' => '50', 'max_uses' => '1'])['errors'] === []);
check('an expired code is saved (for the expiry check below)', $made(['code' => 'OLD', 'type' => 'fixed', 'value' => '5', 'expires_at' => '2020-01-01T00:00'])['errors'] === []);
$bad = $made(['code' => 'RAYA10', 'type' => 'percent', 'value' => '150', 'max_uses' => '0', 'starts_at' => '2030-01-01T10:00', 'expires_at' => '2029-01-01T10:00']);
check('a duplicate code is refused', isset(V::create(['code' => 'RAYA10', 'type' => 'fixed', 'value' => '5'], $actor)['errors']['code']));
check('a percent over 100 is refused', isset($bad['errors']['value']));
check('a use limit of 0 is refused, and an end before the start too', isset($bad['errors']['max_uses']) && isset($bad['errors']['expires_at']));
check('a code with spaces is refused', isset(V::create(['code' => 'BAD CODE', 'type' => 'fixed', 'value' => '5'], $actor)['errors']['code']));

// Checking a code against a subtotal (cents).
check('10% off RM60 is RM6', V::quote('RAYA10', 6000)['discount'] === 600);
check('a fixed RM5 code takes RM5 off', V::quote('save5', 6000)['discount'] === 500);
check('a fixed code never takes more than the items cost', V::quote('SAVE5', 300)['discount'] === 300);
check('a minimum spend is enforced', V::quote('BIG50', 9900)['ok'] === false && str_contains(V::quote('BIG50', 9900)['message'], 'RM100.00'));
check('an expired code says so', V::quote('OLD', 6000)['message'] === 'That code has expired.');
check('an unknown code and a switched-off code get the same answer', V::quote('NOPE', 6000)['message'] === 'That code is not valid.');
V::setActive((int) V::find('SAVE5')['id'], false, $actor);
check('a switched-off code is not valid', V::quote('SAVE5', 6000)['message'] === 'That code is not valid.');
V::setActive((int) V::find('SAVE5')['id'], true, $actor);

// A real checkout: the total takes the discount off the items, not the shipping.
$shipping = Setting::shippingCents();
$o = CheckoutService::place($cart(2), $contact, 'cod', null, null, null, 'RAYA10');
$row = DB::query('SELECT * FROM orders WHERE id = ?', [$o['order_id']])->fetch();
check('the order records the code and the discount', $row['voucher_code'] === 'RAYA10' && $row['discount_total'] === '6.00' && (int) $row['voucher_id'] === (int) V::find('RAYA10')['id']);
check('the total is the items less the discount, plus shipping', $row['total'] === number_format((6000 - 600 + $shipping) / 100, 2, '.', ''));
check('the code is counted as used once', $uses('RAYA10') === 1);

// The client never sets the discount: a code that stopped being valid is refused, and the cart is kept.
$kept = $cart(1);
$refused = false;
try {
    CheckoutService::place($kept, $contact, 'cod', null, null, null, 'OLD');
} catch (CheckoutException $e) {
    $refused = str_contains($e->getMessage(), 'expired');
}
check('an expired code stops the order and keeps the cart', $refused && (int) DB::query('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$kept])->fetchColumn() === 1);

// One-use codes: the second order is refused, and nothing is half-saved.
CheckoutService::place($cart(1), $contact, 'cod', null, null, null, 'ONCE');
$second = $cart(1);
$used = false;
try {
    CheckoutService::place($second, $contact, 'cod', null, null, null, 'ONCE');
} catch (CheckoutException) {
    $used = true;
}
check('a code with no uses left is refused at checkout, and the cart is kept', $used && $uses('ONCE') === 1 && (int) DB::query('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$second])->fetchColumn() === 1);

// Cancelling an order gives its code back.
$err = OrderService::transition((int) $o['order_id'], 'cancelled', $actor, 'test');
check('cancelling the order gives the use back', $err === null && $uses('RAYA10') === 0);

// Affiliates: the discount comes off their base, like shipping does.
$items = [['line_total' => '60.00', 'commission_rate' => '10.00']];
check('the commission base is the items less the discount', AffiliateService::calculate($items, 600) === ['base' => 5400, 'amount' => 540]);
check('with no discount the commission is unchanged', AffiliateService::calculate($items, 0) === ['base' => 6000, 'amount' => 600]);

// Emailing a live code to members. Nothing goes out unless email is set up and the code is live.
DB::query("DELETE FROM settings WHERE `key` LIKE 'email.%'");
User::create('member', 'Ali Member', 'ali-v@example.test', 'Passw0rd!x');
User::create('member', 'Left Member', 'left-v@example.test', 'Passw0rd!x');
UnsubscribeService::unsubscribe('left-v@example.test');
$raya = (int) V::find('RAYA10')['id'];
check('with email not set up, the code is not sent and the admin is told why', str_contains((string) V::emailMembers($raya, $actor), 'Turn email on'));
check('an expired code is not sent', str_contains((string) V::emailMembers((int) V::find('OLD')['id'], $actor), 'cannot be sent'));

$tpl = [];
foreach (S::KINDS as $kind) {
    $tpl[$kind] = ['on' => '1', 'subject' => S::defaults()[$kind]['subject'], 'body' => S::defaults()[$kind]['body']];
}
S::save(['enabled' => '1', 'key' => 're_VOUCHERKEY1234', 'from_name' => 'Lure Shop', 'from_email' => 'shop@example.test', 'reply_to' => '', 'tpl' => $tpl], $actor);
DB::query('DELETE FROM jobs'); // earlier orders queued their own emails; only this section's jobs should run here
$sent = [];
M::setTransport(function (string $m, string $path, string $key, ?string $body, array $headers) use (&$sent): array {
    if ($path !== '/emails/batch') {
        return ['code' => 200, 'json' => ['id' => 're-single']];
    }
    $items = json_decode((string) $body, true);
    foreach ($items as $msg) {
        $sent[] = $msg;
    }
    return ['code' => 200, 'json' => ['data' => array_map(fn($i) => ['id' => "b{$i}"], range(0, count($items) - 1))]];
});
$r = V::emailMembers($raya, $actor);
\App\Services\Queue::run(); // the worker sends the queued code
check('the code goes to active members who have not unsubscribed, and only them', is_array($r) && $r['recipients'] === 1 && $r['queued'] === 1 &&count($sent) === 1 && $sent[0]['to'] === ['ali-v@example.test']);
check('the email carries the code, the offer and an unsubscribe link', str_contains((string) $sent[0]['text'], 'Kod: RAYA10') && str_contains((string) $sent[0]['text'], '10% off the items') && str_contains((string) $sent[0]['text'], '/unsubscribe/'));

// One email per member per code: a second click sends nothing to people who already have it.
$again = V::emailMembers($raya, $actor);
check('a second click refuses when everyone already has the code, and sends nothing', is_string($again) && str_contains($again, 'already received') && count($sent) === 1);
User::create('member', 'Bo Member', 'bo-v@example.test', 'Passw0rd!x');
$sent = [];
$third = V::emailMembers($raya, $actor);
\App\Services\Queue::run();
check('a member added later gets the code, and only them', is_array($third) && $third['recipients'] === 1 && $third['already'] === 1 && count($sent) === 1 && $sent[0]['to'] === ['bo-v@example.test']);
check('the voucher emails are logged as voucher type', (string) DB::query("SELECT kind FROM email_log WHERE to_email = 'bo-v@example.test' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'voucher');
check('the code is recorded for each member it reached',(int) DB::query('SELECT COUNT(*) FROM voucher_sends WHERE voucher_id = ?', [$raya])->fetchColumn() === 2);

// A member whose email fails is not recorded as sent, and the worker queues them again five minutes later.
User::create('member', 'Cy Member', 'cy-v@example.test', 'Passw0rd!x');
M::setTransport(function (string $m, string $path, string $key, ?string $body, array $headers): array {
    return ['code' => 500, 'json' => ['message' => 'temporary']];
});
$failed = V::emailMembers($raya, $actor);
\App\Services\Queue::run();
check('a failed email is not recorded as sent', is_array($failed) && (int) DB::query('SELECT COUNT(*) FROM voucher_sends WHERE voucher_id = ?', [$raya])->fetchColumn() === 2);
$retryJob = DB::query("SELECT payload, run_at > NOW() AS later FROM jobs WHERE type = 'email.batch' AND status = 'pending'")->fetch();
check('the failed member is queued again, five minutes later', $retryJob !== false && (int) $retryJob['later'] === 1 && str_contains((string) $retryJob['payload'], 'cy-v@example.test'));

// The retry is due and the provider accepts it: only that member gets the code.
DB::query("UPDATE jobs SET run_at = NOW() WHERE status = 'pending'");
M::setTransport(function (string $m, string $path, string $key, ?string $body, array $headers) use (&$sent): array {
    $items = json_decode((string) $body, true);
    foreach ($items as $msg) {
        $sent[] = $msg;
    }
    return ['code' => 200, 'json' => ['data' => array_map(fn($i) => ['id' => "b{$i}"], range(0, count($items) - 1))]];
});
$sent = [];
\App\Services\Queue::run();
check('the retry sends only the member whose email failed', count($sent) === 1 && $sent[0]['to'] === ['cy-v@example.test']);
check('and the code is now recorded for them too', (int) DB::query('SELECT COUNT(*) FROM voucher_sends WHERE voucher_id = ?', [$raya])->fetchColumn() === 3);
