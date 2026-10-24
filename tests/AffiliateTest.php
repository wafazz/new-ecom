<?php
use App\Services\AffiliateService as A;

// Commission base = (sales price - shipping) x rate, per line; shipping is shared by line sales.
$item = fn(string $lineTotal, ?string $rate) => ['line_total' => $lineTotal, 'commission_rate' => $rate];

check('10% of 30.00 sales with no shipping = 3.00', A::calculate([$item('30.00', '10.00')]) === ['base' => 3000, 'amount' => 300]);
check('shipping is taken off the sales first: (100.00 - 10.00) x 10% = 9.00', A::calculate([$item('100.00', '10.00')], 1000) === ['base' => 9000, 'amount' => 900]);
check('shipping is shared by line sales: 60 and 40 sales, 10 shipping, rates 10% and 50%', A::calculate([$item('60.00', '10.00'), $item('40.00', '50.00')], 1000)['amount'] === 2340);
check('rounds half up per line (10.05 x 10% = 1.005 -> 1.01)', A::calculate([$item('10.05', '10.00')])['amount'] === 101);
check('shipping above the sales gives no negative base', A::calculate([$item('5.00', '10.00')], 1000) === ['base' => 0, 'amount' => 0]);
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
