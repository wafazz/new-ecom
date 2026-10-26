<?php
// Flash sale pricing rules and form validation, no database needed.
use App\Services\FlashSaleService as F;
use App\Services\PricingService as P;

$now = new DateTimeImmutable('2026-06-15 12:00:00');
$live = fn(array $extra = []) => [...['retail_price' => '20.00', 'discount_price' => null, 'discount_start' => null, 'discount_end' => null,
    'flash_percent' => '25.00', 'flash_ends_at' => '2026-06-15 18:00:00', 'flash_name' => 'Weekend'], ...$extra];

$r = P::effective($live(), null, $now);
check('flash takes its % off the retail price', $r['price'] === '15.00' && $r['retail'] === '20.00' && $r['on_sale'] === true);
check('flash reports its sale name and end', $r['flash'] === ['percent' => '25.00', 'ends_at' => '2026-06-15 18:00:00', 'name' => 'Weekend']);

$r = P::effective($live(['discount_price' => '15.00']), null, $now);
check('flash stacks on a running discount price (25% off 15.00 = 11.25)', $r['price'] === '11.25' && $r['retail'] === '20.00');

check('flash ended: back to the normal price', P::effective($live(['flash_ends_at' => '2026-06-15 11:59:00']), null, $now)['price'] === '20.00');
check('flash ended: no flash in the result', P::effective($live(['flash_ends_at' => '2026-06-15 11:59:00']), null, $now)['flash'] === null);
check('no flash fields: unchanged', P::effective(['retail_price' => '20.00', 'discount_price' => null, 'discount_start' => null, 'discount_end' => null], null, $now)['flash'] === null);
check('zero percent is no sale', P::effective($live(['flash_percent' => '0.00']), null, $now)['flash'] === null);

check('rounds to the cent (19.99 at 10% = 17.99)', P::effective($live(['retail_price' => '19.99', 'flash_percent' => '10.00']), null, $now)['price'] === '17.99');
// 20.00 at 0.01% is 19.998, which rounds to 20.00: not below retail, so it is not a sale.
check('never above or equal to retail', P::effective($live(['flash_percent' => '0.01']), null, $now)['flash'] === null);

$variant = ['retail_price' => '30.00', 'discount_price' => null];
$r = P::effective($live(['flash_percent' => '10.00']), $variant, $now);
check('variant price takes the flash % too', $r['price'] === '27.00' && $r['retail'] === '30.00');

// Validation: the form's own rules.
$ok = fn(array $in) => F::validate($in)['errors'] === [];
$base = ['name' => 'Weekend', 'starts_at' => '2026-06-15T10:00', 'ends_at' => '2026-06-15T18:00', 'items' => [7 => ['on' => '1', 'percent' => '25']]];
check('valid sale passes', $ok($base));
check('name required', isset(F::validate([...$base, 'name' => ''])['errors']['name']));
check('name at most 120 characters', isset(F::validate([...$base, 'name' => str_repeat('a', 121)])['errors']['name']));
check('end before start refused', isset(F::validate([...$base, 'ends_at' => '2026-06-15T09:00'])['errors']['ends_at']));
check('missing start refused', isset(F::validate([...$base, 'starts_at' => ''])['errors']['starts_at']));
check('bad date refused', isset(F::validate([...$base, 'starts_at' => 'tomorrow'])['errors']['starts_at']));
check('no product refused', isset(F::validate([...$base, 'items' => []])['errors']['items']));
check('percent above max refused', isset(F::validate([...$base, 'items' => [7 => ['on' => '1', 'percent' => '95']]])['errors']['item_7']));
check('percent of zero refused', isset(F::validate([...$base, 'items' => [7 => ['on' => '1', 'percent' => '0']]])['errors']['item_7']));
check('percent with three decimals refused', isset(F::validate([...$base, 'items' => [7 => ['on' => '1', 'percent' => '12.345']]])['errors']['item_7']));
check('decimal percent accepted (12.5)', $ok([...$base, 'items' => [7 => ['on' => '1', 'percent' => '12.5']]]));
check('unticked row ignored', F::items([7 => ['on' => '', 'percent' => '25'], 8 => ['on' => '1', 'percent' => '10']]) === [8 => '10.00']);
check('ticked row with bad percent is not a pick', !isset(F::items([7 => ['on' => '1', 'percent' => 'abc']])[7]));
check('preview of an empty sale is null', F::preview(['name' => 'x', 'starts_at' => '2026-06-15T10:00', 'ends_at' => '2099-01-01T00:00', 'items' => []]) === null);
check('preview of a past end is null', F::preview([...$base, 'ends_at' => '2020-01-01T00:00', 'items' => [7 => ['on' => '1', 'percent' => '25']]]) === null);
