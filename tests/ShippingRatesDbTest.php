<?php
// Shipping: zones, weight rounded up, first and next kilo, COD surcharge tiers, missing weight or rates, and rate validation.
// Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\User;
use App\Services\CheckoutException;
use App\Services\ShippingRates as S;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'shipping.%'");
$shipAdmin = User::create('admin', 'Ship Admin', 'ship@example.test', 'Passw0rd!x');

$shipRefuses = function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (CheckoutException) {
        return true;
    }
};
$shipLine = fn($kg, int $qty = 1) => ['weight_kg' => (string) $kg, 'qty' => $qty];

check('Sabah, Sarawak and Labuan are zone 2; everywhere else is zone 1', S::zoneFor('Sabah') === 'zone2' && S::zoneFor('Sarawak') === 'zone2' && S::zoneFor('Labuan') === 'zone2' && S::zoneFor('Selangor') === 'zone1' && S::zoneFor('Kuala Lumpur') === 'zone1');
check('checkout is refused until a zone has all its rates', $shipRefuses(fn() => S::quote([$shipLine(1)], 5000, 'Selangor', 'bank_transfer')));

$shipZone = function (string $first, string $next, string $benchmark, string $le, string $gt): array {
    return ['first' => $first, 'next' => $next, 'benchmark' => $benchmark, 'cod_at_or_below' => $le, 'cod_above' => $gt];
};
$shipErrors = S::save(['zone1' => $shipZone('8.00', '2.00', '100.00', '5', '3'), 'zone2' => $shipZone('15.00', '5.00', '150.00', '6', '4')], (int) $shipAdmin);
check('both zones save without errors', $shipErrors === []);
check('a percent above 100 is refused', S::save(['zone1' => $shipZone('8', '2', '100', '150', '3'), 'zone2' => []], (int) $shipAdmin) !== []);

// Zone 1 (Peninsular): normal postage, then the COD tiers.
check('0.5 kg is one kilo: the first kilo rate only', S::quote([$shipLine(0.5)], 5000, 'Selangor', 'bank_transfer')['fee'] === 800);
check('1.2 kg is charged as 2 kilos, and the real weight is still shown', (function () use ($shipLine) {
    $q = S::quote([$shipLine(1.2)], 5000, 'Selangor', 'bank_transfer');
    return $q['fee'] === 1000 && $q['kg'] === 2 && $q['actual_kg'] === '1.200';
})());
check('3 kg is first kilo plus two next kilos', S::quote([$shipLine(3)], 5000, 'Selangor', 'bank_transfer')['fee'] === 1200);
check('weights of the whole cart are added before rounding: 2 x 0.6 kg = 1.2 kg = 2 kilos', S::quote([$shipLine(0.6, 2)], 5000, 'Selangor', 'bank_transfer')['fee'] === 1000);
check('non-COD pays normal postage only', S::quote([$shipLine(1)], 50000, 'Selangor', 'bank_transfer')['cod'] === 0);
check('COD at or below the benchmark: normal plus 5% of the sales before shipping', (function () use ($shipLine) {
    $q = S::quote([$shipLine(1)], 5000, 'Selangor', 'cod');
    return $q['cod'] === 250 && $q['fee'] === 1050;
})());
check('COD above the benchmark: normal plus 3% of the sales', (function () use ($shipLine) {
    $q = S::quote([$shipLine(1)], 15000, 'Selangor', 'cod');
    return $q['cod'] === 450 && $q['fee'] === 1250;
})());
check('the benchmark itself counts as "at or below"', S::quote([$shipLine(1)], 10000, 'Selangor', 'cod')['cod'] === 500);

// Zone 2 (Sabah, Sarawak, Labuan): its own rates.
check('zone 2 uses its own first kilo rate', S::quote([$shipLine(1)], 5000, 'Sabah', 'bank_transfer')['fee'] === 1500);
check('zone 2 COD above its own benchmark uses its own percent', (function () use ($shipLine) {
    $q = S::quote([$shipLine(1)], 20000, 'Labuan', 'cod');
    return $q['zone'] === 'zone2' && $q['cod'] === 800 && $q['fee'] === 2300;
})());

// Refusals.
check('an item with no weight stops the quote', $shipRefuses(fn() => S::quote([$shipLine(0)], 5000, 'Selangor', 'bank_transfer')));
check('an item with no weight stored (null) stops the quote', $shipRefuses(fn() => S::quote([['weight_kg' => null, 'qty' => 1]], 5000, 'Selangor', 'bank_transfer')));

// Leave the default postage in place for the DB tests that run after this one.
seed_default_shipping();
