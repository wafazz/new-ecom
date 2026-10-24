<?php
use App\Services\PricingService as P;

$ok = fn(array $in) => P::validate($in)['errors'] === [];
$base = ['base_price' => '10', 'company_base_price' => '15.50', 'retail_price' => '20', 'discount_price' => ''];

check('valid prices pass', $ok($base));
check('normalises to 2dp', P::validate($base)['values']['company_base_price'] === '15.50' && P::validate($base)['values']['base_price'] === '10.00');
check('blank discount -> null', P::validate($base)['values']['discount_price'] === null);
check('company below base rejected', isset(P::validate([...$base, 'company_base_price' => '9.99'])['errors']['company_base_price']));
check('retail below company rejected', isset(P::validate([...$base, 'retail_price' => '15.49'])['errors']['retail_price']));
check('equal tiers allowed', $ok(['base_price' => '5', 'company_base_price' => '5', 'retail_price' => '5', 'discount_price' => '5']));
check('discount above retail rejected', isset(P::validate([...$base, 'discount_price' => '20.01'])['errors']['discount_price']));
check('required fields', count(P::validate(['discount_price' => ''])['errors']) === 3);
check('rejects 3dp / negative / text', !$ok([...$base, 'retail_price' => '20.123']) && !$ok([...$base, 'base_price' => '-1']) && !$ok([...$base, 'base_price' => 'abc']));
check('float-trap 0.1+0.2 style', P::cents('0.30') === 30 && P::cents('19.99') === 1999 && P::cents('7') === 700 && P::cents('7.5') === 750);

$prod = ['retail_price' => '20.00', 'discount_price' => '15.00', 'discount_start' => null, 'discount_end' => null];
$now = new DateTimeImmutable('2026-06-15 12:00:00');
check('discount active, no window', P::effective($prod, null, $now) === ['price' => '15.00', 'retail' => '20.00', 'on_sale' => true]);
check('before window', P::effective([...$prod, 'discount_start' => '2026-07-01 00:00:00'], null, $now)['on_sale'] === false);
check('after window', P::effective([...$prod, 'discount_end' => '2026-06-01 00:00:00'], null, $now)['price'] === '20.00');
check('inside window', P::effective([...$prod, 'discount_start' => '2026-06-01 00:00:00', 'discount_end' => '2026-06-30 00:00:00'], null, $now)['on_sale'] === true);
check('no discount -> retail', P::effective([...$prod, 'discount_price' => null], null, $now)['price'] === '20.00');
check('discount == retail is not a sale', P::effective([...$prod, 'discount_price' => '20.00'], null, $now)['on_sale'] === false);
$var = ['retail_price' => '30.00', 'discount_price' => null];
check('variant retail overrides, inherits discount only if lower', P::effective($prod, $var, $now)['price'] === '15.00');
check('variant discount overrides', P::effective($prod, ['retail_price' => null, 'discount_price' => '12.00'], $now)['price'] === '12.00');
check('resolveVariant inherits nulls', P::resolveVariant(['base_price' => '1', 'company_base_price' => '2', 'retail_price' => '3', 'discount_price' => null], ['retail_price' => '9', 'base_price' => null, 'company_base_price' => null, 'discount_price' => null])['retail_price'] === '9');

[$s, $e, $err] = P::window('2026-06-01T10:00', '2026-06-02T10:00');
check('window parses', $s === '2026-06-01 10:00:00' && $e === '2026-06-02 10:00:00' && $err === []);
check('window end<=start rejected', isset(P::window('2026-06-02T10:00', '2026-06-01T10:00')[2]['discount_end']));
check('window garbage rejected', isset(P::window('nope', '')[2]['discount_start']));
check('window blank ok', P::window('', '') === [null, null, []]);
