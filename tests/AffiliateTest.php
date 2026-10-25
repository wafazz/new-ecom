<?php
use App\Services\AffiliateService as A;

// Commission base = (sales paid - shipping) x rate, per line. Sales paid here are the item line totals, which already
// exclude shipping, so shipping is not taken off again. A discount code is shared by line sales.
$item = fn(string $lineTotal, ?string $rate) => ['line_total' => $lineTotal, 'commission_rate' => $rate];

check('10% of 30.00 sales with no shipping = 3.00', A::calculate([$item('30.00', '10.00')]) === ['base' => 3000, 'amount' => 300]);
check('shipping is not taken off a second time: 100.00 items at 10% = 10.00', A::calculate([$item('100.00', '10.00')]) === ['base' => 10000, 'amount' => 1000]);
check('RM19 item with RM10 shipping (buyer paid RM29) earns 10% of RM19 = 1.90', A::calculate([$item('19.00', '10.00')]) === ['base' => 1900, 'amount' => 190]);
check('a discount is shared by line sales: 60 and 40 sales, 10 discount, rates 10% and 50%', A::calculate([$item('60.00', '10.00'), $item('40.00', '50.00')], 1000)['amount'] === 2340);
check('rounds half up per line (10.05 x 10% = 1.005 -> 1.01)', A::calculate([$item('10.05', '10.00')])['amount'] === 101);
check('a discount above the sales gives no negative base', A::calculate([$item('5.00', '10.00')], 1000) === ['base' => 0, 'amount' => 0]);
check('lines without a rate (no affiliate) are skipped', A::calculate([$item('100.00', null)], 500) === ['base' => 0, 'amount' => 0]);
check('a 100% rate takes the whole net sales', A::calculate([$item('9.99', '100.00')])['amount'] === 999);
check('no items gives nothing', A::calculate([]) === ['base' => 0, 'amount' => 0]);

check('rate accepts 0, 7.5, 100', A::isRate('0') && A::isRate('7.5') && A::isRate('100.00'));
check('rate rejects 100.01, -1, abc, 1e2, empty', !A::isRate('100.01') && !A::isRate('-1') && !A::isRate('abc') && !A::isRate('1e2') && !A::isRate(''));

check('code normalises case and whitespace', A::normalise(' abcd2345 ') === 'ABCD2345');
check('code rejects ambiguous chars and wrong length', A::normalise('ABCD234I') === null && A::normalise('ABCD2340') === null && A::normalise('ABC') === null && A::normalise("ABCD2345\n; DROP") === null);

$aff = ['id' => 7, 'email' => 'Aff@Example.com'];
check('self-referral by user id', A::isSelfReferral($aff, 7, 'other@x.com'));
check('self-referral by email (case-insensitive, guest)', A::isSelfReferral($aff, null, ' aff@example.COM '));
check('different buyer is not self-referral', !A::isSelfReferral($aff, 8, 'buyer@x.com'));
