<?php
// Recalculating open commissions: a dry run writes nothing, --apply fixes pending and approved ones, and any part already
// paid is left alone. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\User;
use App\Services\AffiliateService;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
$aff = User::create('member', 'Recalc Affiliate', 'recalc@example.test', 'Passw0rd!x');

// An order: items RM19.00 (10% rate), RM10.00 shipping, so the buyer paid RM29.00. Old commission stored RM0.90 (9.00 base).
$order = function (string $no) use ($aff): int {
    DB::query("INSERT INTO orders (order_no, user_id, affiliate_id, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, discount_total, payment_method, payment_status, status) VALUES (?, NULL, ?, 'Buyer', 'buyer@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 19, 10, 29, 0, 'cod', 'paid', 'completed')", [$no, $aff]);
    $id = (int) DB::connection()->lastInsertId();
    DB::query("INSERT INTO order_items (order_id, product_name, unit_price, retail_price, base_price, company_base_price, qty, line_total, commission_rate) VALUES (?, 'Item', 19, 19, 5, 10, 1, 19, 10)", [$id]);
    return $id;
};
$commission = function (int $orderId, string $base, string $amount, string $status, string $paid = '0.00') use ($aff): int {
    DB::query('INSERT INTO affiliate_commissions (order_id, affiliate_id, base_amount, amount, status, paid_amount) VALUES (?, ?, ?, ?, ?, ?)', [$orderId, $aff, $base, $amount, $status, $paid]);
    return (int) DB::connection()->lastInsertId();
};
$pending = $commission($order('RC-1'), '9.00', '0.90', 'pending');
$approved = $commission($order('RC-2'), '9.00', '0.90', 'approved');
$partlyPaid = $commission($order('RC-3'), '9.00', '0.90', 'approved', '0.50');
$paidOut = $commission($order('RC-4'), '9.00', '0.90', 'paid', '0.90');
$row = fn(int $id) => DB::query('SELECT base_amount, amount FROM affiliate_commissions WHERE id = ?', [$id])->fetch();

$dry = AffiliateService::recalculateOpen(false);
check('a dry run finds the two open commissions to fix', count($dry['changes']) === 2 && $dry['skipped'] === 1);
check('a dry run writes nothing', $row($pending)['amount'] === '0.90' && $row($approved)['base_amount'] === '9.00');

$applied = AffiliateService::recalculateOpen(true, null);
check('applying fixes pending and approved: RM19 at 10% is RM1.90', $row($pending)['amount'] === '1.90' && $row($pending)['base_amount'] === '19.00');
check('the approved one is fixed too', $row($approved)['amount'] === '1.90' && count($applied['changes']) === 2);
check('a commission already part paid is not changed', $row($partlyPaid)['amount'] === '0.90' && $applied['skipped'] === 1);
check('a paid commission is not changed', $row($paidOut)['amount'] === '0.90');
check('a second run has nothing left to change', AffiliateService::recalculateOpen(true, null)['changes'] === []);

$solo = $commission($order('RC-5'), '9.00', '0.90', 'pending');
$one = AffiliateService::recalculateOpen(true, null, $solo);
check('one commission can be recalculated on its own', count($one['changes']) === 1 && $row($solo)['amount'] === '1.90');
check('a paid commission cannot be recalculated by its id', AffiliateService::recalculateOpen(true, null, $paidOut)['changes'] === []);
