<?php
// Affiliate withdrawals: requests, the RM1.00 fee, holds on the balance, paying oldest commissions first (in parts), rejects, and encryption. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Core\SecretBox;
use App\Models\User;
use App\Services\AffiliateService;
use App\Services\AffiliateWithdrawalService as W;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
putenv('APP_KEY=withdraw-db-test-key-0123456789abcdef0123');
$admin = User::create('admin', 'Hq Admin', 'hq@example.test', 'Passw0rd!x');
$aff = User::create('member', 'Affiliate One', 'aff@example.test', 'Passw0rd!x');

$orderNo = 0;
$commission = function (string $amount) use ($aff, &$orderNo): int {
    $orderNo++;
    DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES (?, 'Buyer', 'buyer@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', ?, 0, ?, 'cod', 'paid', 'completed')", ["WD-{$orderNo}", $amount, $amount]);
    $order = (int) DB::connection()->lastInsertId();
    DB::query("INSERT INTO affiliate_commissions (order_id, affiliate_id, base_amount, amount, status) VALUES (?, ?, ?, ?, 'approved')", [$order, $aff, $amount, $amount]);
    return (int) DB::connection()->lastInsertId();
};
$first = $commission('30.00');
$second = $commission('20.00');
$bal = fn() => W::balance($aff)['available'];
$ask = fn(string $amount) => W::request($aff, $amount, 'Maybank', 'Affiliate One', '1234 5678 90');

check('the balance is the approved commission total', $bal() === 5000);
check('a request above the balance is refused, with the amount it can take', str_contains((string) $ask('50.01')['error'], 'RM50.00'));
check('RM1.00 or less is refused, since the fee would take it all', $ask('1.00')['error'] !== null && $ask('0.50')['error'] !== null);
check('a bank not on the list is refused', W::request($aff, '10.00', 'Not A Bank', 'Affiliate One', '1234567890')['error'] !== null);
check('a bad account number is refused', W::request($aff, '10.00', 'Maybank', 'Affiliate One', '12')['error'] !== null);

$r1 = $ask('20.00');
check('a request is accepted', $r1['error'] === null && $r1['id'] !== null);
$row = DB::query('SELECT * FROM affiliate_withdrawals WHERE id = ?', [$r1['id']])->fetch();
check('RM20.00 requested: RM1.00 fee, the member receives RM19.00', $row['amount'] === '20.00' && $row['fee'] === '1.00' && $row['net'] === '19.00');
check('the account number is stored encrypted and reads back', $row['account_no_enc'] !== '1234567890' && SecretBox::decrypt((string) $row['account_no_enc']) === '1234567890');
check('the requested amount is held back from the balance', $bal() === 3000);
check('a second request while one is waiting is refused', $ask('5.00')['error'] !== null);
check('HQ sees the pending request, with the account number to transfer to', (string) W::listFor('pending')[0]['account_no'] === '1234567890');

check('rejecting a request gives the amount back', W::reject((int) $r1['id'], (int) $admin, 'Wrong account') === null && $bal() === 5000);
check('a rejected request cannot be paid', W::markPaid((int) $r1['id'], (int) $admin, 'REF-X') !== null);

// Paying: the oldest commission goes first, and a commission can be paid in parts.
$r2 = $ask('25.00');
check('a second request for RM25.00 is accepted', $r2['error'] === null);
check('paying needs the bank reference', W::markPaid((int) $r2['id'], (int) $admin, '') !== null);
check('paying takes RM25.00 from the oldest commission', W::markPaid((int) $r2['id'], (int) $admin, 'MB-REF-1') === null);
$c1 = DB::query('SELECT amount, paid_amount, status FROM affiliate_commissions WHERE id = ?', [$first])->fetch();
$c2 = DB::query('SELECT amount, paid_amount, status FROM affiliate_commissions WHERE id = ?', [$second])->fetch();
check('the oldest commission is part paid and still owed the rest', $c1['paid_amount'] === '25.00' && $c1['status'] === 'approved');
check('the newer commission is untouched', $c2['paid_amount'] === '0.00' && $c2['status'] === 'approved');
check('the paid withdrawal shows which commission it came from', (int) DB::query('SELECT COUNT(*) FROM affiliate_withdrawal_items WHERE withdrawal_id = ? AND commission_id = ? AND amount = 25.00', [$r2['id'], $first])->fetchColumn() === 1);
check('the member gets a payout record for the amount they receive', (int) DB::query("SELECT COUNT(*) FROM affiliate_payouts WHERE affiliate_id = ? AND amount = 24.00 AND reference = 'MB-REF-1'", [$aff])->fetchColumn() === 1);
check('the balance now counts only what is still owed', $bal() === 2500);
check('a paid withdrawal cannot be paid again', W::markPaid((int) $r2['id'], (int) $admin, 'MB-REF-2') !== null);

// A commission finished off by a later withdrawal is marked paid.
$r3 = $ask('25.00');
check('the last RM25.00 can be requested', $r3['error'] === null && $bal() === 0);
check('paying it finishes both commissions', W::markPaid((int) $r3['id'], (int) $admin, 'MB-REF-3') === null
    && DB::query('SELECT status FROM affiliate_commissions WHERE id = ?', [$first])->fetchColumn() === 'paid'
    && DB::query('SELECT status FROM affiliate_commissions WHERE id = ?', [$second])->fetchColumn() === 'paid');
check('nothing is left to withdraw', $ask('2.00')['error'] !== null);

$stats = AffiliateService::stats($aff);
check('the affiliate stats show nothing owed and RM50.00 paid out', (float) $stats['approved'] === 0.0 && (float) $stats['paid'] === 50.0);
