<?php
use App\Services\AffiliateService as A;

$item = fn(string $unit, string $company, int $qty, ?string $rate) => ['unit_price' => $unit, 'company_base_price' => $company, 'qty' => $qty, 'commission_rate' => $rate];

check('10% of 30.00 margin x2 = 6.00', A::calculate([$item('100.00', '70.00', 2, '10.00')]) === ['base' => 6000, 'amount' => 600]);
check('rounds half up per line (0.05 x 10% = 0.005 -> 0.01)', A::calculate([$item('10.05', '10.00', 1, '10.00')])['amount'] === 1);
check('below company base earns nothing (no negative margin)', A::calculate([$item('50.00', '70.00', 3, '10.00')]) === ['base' => 0, 'amount' => 0]);
check('lines without a rate (no affiliate) are skipped', A::calculate([$item('100.00', '70.00', 1, null)]) === ['base' => 0, 'amount' => 0]);
check('mixed rates sum per line', A::calculate([$item('100.00', '80.00', 1, '10.00'), $item('50.00', '40.00', 1, '50.00')])['amount'] === 700);
check('100% rate takes the whole margin', A::calculate([$item('9.99', '5.00', 1, '100.00')])['amount'] === 499);

check('rate accepts 0, 7.5, 100', A::isRate('0') && A::isRate('7.5') && A::isRate('100.00'));
check('rate rejects 100.01, -1, abc, 1e2, empty', !A::isRate('100.01') && !A::isRate('-1') && !A::isRate('abc') && !A::isRate('1e2') && !A::isRate(''));

check('code normalises case and whitespace', A::normalise(' abcd2345 ') === 'ABCD2345');
check('code rejects ambiguous chars and wrong length', A::normalise('ABCD234I') === null && A::normalise('ABCD2340') === null && A::normalise('ABC') === null && A::normalise("ABCD2345\n; DROP") === null);

$aff = ['id' => 7, 'email' => 'Aff@Example.com'];
check('self-referral by user id', A::isSelfReferral($aff, 7, 'other@x.com'));
check('self-referral by email (case-insensitive, guest)', A::isSelfReferral($aff, null, ' aff@example.COM '));
check('different buyer is not self-referral', !A::isSelfReferral($aff, 8, 'buyer@x.com'));
