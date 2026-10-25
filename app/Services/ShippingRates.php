<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/**
 * Postage by zone and weight. Two zones: Peninsular Malaysia, and Sabah, Sarawak and Labuan.
 * Normal postage = first kilo rate + each next kilo (the total weight rounded up to whole kilos, at least 1).
 * COD adds a surcharge: a percent of the items' sales (after any discount, before shipping), with one percent
 * when the sales are at or below the zone's benchmark and another when above it.
 * Money is integer cents; rates are read from settings and never from the browser.
 */
final class ShippingRates
{
    public const ZONES = ['zone1' => 'Peninsular Malaysia', 'zone2' => 'Sabah, Sarawak and Labuan'];
    private const ZONE2_STATES = ['Sabah', 'Sarawak', 'Labuan'];

    /** The fields each zone has: key => label. */
    public const FIELDS = [
        'first' => 'First kilo rate (RM)',
        'next' => 'Each next kilo (RM)',
        'benchmark' => 'Benchmark sales price (RM)',
        'cod_at_or_below' => 'COD % when sales are at or below the benchmark',
        'cod_above' => 'COD % when sales are above the benchmark',
    ];

    public static function zoneFor(string $state): string
    {
        return in_array($state, self::ZONE2_STATES, true) ? 'zone2' : 'zone1';
    }

    /** Rates for a zone in cents (percents as "5.00" -> 500), or null while any field is still empty. */
    public static function rates(string $zone): ?array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $k) {
            $v = (string) Setting::get("shipping.{$zone}.{$k}", '');
            if ($v === '') {
                return null;
            }
            $out[$k] = PricingService::cents($v);
        }
        return $out;
    }

    /**
     * Price the delivery.
     * @param array<int,array{weight_kg:string|float|null, qty:int}> $lines
     * @param int    $subtotalCents items after discount, before shipping
     * @param string $method        payment method code; 'cod' adds the COD surcharge
     * @return array{zone:string, kg:int, actual_kg:string, normal:int, cod:int, fee:int} cents
     * @throws CheckoutException when a product has no weight, or the zone's rates are not set
     */
    public static function quote(array $lines, int $subtotalCents, string $state, string $method): array
    {
        $zone = self::zoneFor($state);
        $r = self::rates($zone);
        if ($r === null) {
            throw new CheckoutException('Shipping rates for ' . self::ZONES[$zone] . ' are not set yet. Please try again later.');
        }

        $grams = 0;
        foreach ($lines as $l) {
            $w = (float) ($l['weight_kg'] ?? 0);
            if ($w <= 0) {
                throw new CheckoutException('An item in your cart has no weight set, so shipping cannot be worked out. Please remove it or try again later.');
            }
            $grams += (int) round($w * 1000) * (int) $l['qty'];
        }
        $kg = max(1, (int) ceil($grams / 1000));
        $normal = $r['first'] + max(0, $kg - 1) * $r['next'];

        $cod = 0;
        if ($method === 'cod') {
            $pct = $subtotalCents <= $r['benchmark'] ? $r['cod_at_or_below'] : $r['cod_above'];
            $cod = intdiv($subtotalCents * $pct + 5000, 10000);
        }
        return [
            'zone' => $zone,
            'kg' => $kg,
            'actual_kg' => number_format($grams / 1000, 3, '.', ''), // shown to the customer; the price uses $kg
            'normal' => $normal,
            'cod' => $cod,
            'fee' => $normal + $cod,
        ];
    }

    /**
     * Save one zone's rates, on its own: the other zone is not checked or changed. Blank input keeps the stored value,
     * but a zone that has never been complete must be filled in first. Returns the errors (empty when saved).
     * @param array<string,string> $in field => value
     * @return string[] errors
     */
    public static function saveZone(string $zone, array $in, int $actorId): array
    {
        if (!isset(self::ZONES[$zone])) {
            return ['Unknown shipping zone.'];
        }
        $errors = [];
        foreach (self::FIELDS as $k => $label) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') {
                if (self::rates($zone) === null) {
                    $errors[] = $label . ' is required.';
                }
                continue;
            }
            $isPercent = str_starts_with($k, 'cod_');
            $ok = $isPercent
                ? preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v) === 1 && (float) $v <= 100
                : PricingService::isMoney($v);
            if (!$ok) {
                $errors[] = $label . ' is not a valid ' . ($isPercent ? 'percent (0 to 100).' : 'amount.');
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        $changed = [];
        foreach (array_keys(self::FIELDS) as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v !== '' && $v !== (string) Setting::get("shipping.{$zone}.{$k}", '')) {
                Setting::set("shipping.{$zone}.{$k}", $v);
                $changed[] = $k;
            }
        }
        if ($changed !== []) {
            AuditService::log($actorId, 'shipping.rates', self::ZONES[$zone] . ' changed: ' . implode(', ', $changed));
        }
        return [];
    }

    /**
     * Save both zones at once (each one separately checked). @param array<string,array<string,string>> $in zone => field => value
     * @return string[] errors, prefixed with the zone's name
     */
    public static function save(array $in, int $actorId): array
    {
        $errors = [];
        foreach (array_keys(self::ZONES) as $zone) {
            foreach (self::saveZone($zone, is_array($in[$zone] ?? null) ? $in[$zone] : [], $actorId) as $e) {
                $errors[] = self::ZONES[$zone] . ': ' . $e;
            }
        }
        return $errors;
    }

    /** What the settings page shows: the stored value for each zone and field, as typed. */
    public static function overview(): array
    {
        $out = [];
        foreach (array_keys(self::ZONES) as $zone) {
            foreach (array_keys(self::FIELDS) as $k) {
                $out[$zone][$k] = (string) Setting::get("shipping.{$zone}.{$k}", '');
            }
        }
        return $out;
    }
}
