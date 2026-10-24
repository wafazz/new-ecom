<?php
declare(strict_types=1);

namespace App\Services;

/**
 * All money maths here is in integer cents. Rule: retail >= company base >= base (cost);
 * discount (optional) <= retail. Variants leave a price blank (NULL) to inherit the product's.
 */
final class PricingService
{
    public const FIELDS = ['base_price', 'company_base_price', 'retail_price', 'discount_price'];
    private const LABELS = [
        'base_price' => 'Base price',
        'company_base_price' => 'Company base price',
        'retail_price' => 'Retail price',
        'discount_price' => 'Discount price',
    ];

    public static function isMoney(string $v): bool
    {
        return (bool) preg_match('/^\d{1,8}(\.\d{1,2})?$/', $v);
    }

    public static function cents(string $v): int
    {
        [$whole, $frac] = array_pad(explode('.', $v, 2), 2, '');
        return (int) $whole * 100 + (int) str_pad($frac, 2, '0');
    }

    public static function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * @param array<string,?string> $in raw strings; discount_price may be ''/null
     * @return array{values: array<string,?string>, errors: array<string,string>} values are normalised "12.50" strings
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $cents = [];
        foreach (self::FIELDS as $f) {
            $raw = trim((string) ($in[$f] ?? ''));
            if ($raw === '') {
                if ($f !== 'discount_price') {
                    $errors[$f] = self::LABELS[$f] . ' is required.';
                }
                $cents[$f] = null;
            } elseif (!self::isMoney($raw)) {
                $errors[$f] = self::LABELS[$f] . ' must be an amount like 12.50.';
                $cents[$f] = null;
            } else {
                $cents[$f] = self::cents($raw);
            }
        }

        if ($errors === []) {
            if ($cents['company_base_price'] < $cents['base_price']) {
                $errors['company_base_price'] = 'Company base price cannot be below the base price.';
            }
            if ($cents['retail_price'] < $cents['company_base_price']) {
                $errors['retail_price'] = 'Retail price cannot be below the company base price.';
            }
            if ($cents['discount_price'] !== null && $cents['discount_price'] > $cents['retail_price']) {
                $errors['discount_price'] = 'Discount price cannot be above the retail price.';
            }
        }

        $values = array_map(fn($c) => $c === null ? null : self::format($c), $cents);
        return ['values' => $values, 'errors' => $errors];
    }

    /** Validate optional "Y-m-d\TH:i" window inputs. Returns [startSql|null, endSql|null, errors]. */
    public static function window(?string $start, ?string $end): array
    {
        $errors = [];
        $parse = function (?string $v, string $field) use (&$errors): ?\DateTimeImmutable {
            $v = trim((string) $v);
            if ($v === '') {
                return null;
            }
            $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $v);
            if ($d === false || $d->format('Y-m-d\TH:i') !== $v) {
                $errors[$field] = 'Invalid date/time.';
                return null;
            }
            return $d;
        };
        $s = $parse($start, 'discount_start');
        $e = $parse($end, 'discount_end');
        if ($s && $e && $e <= $s) {
            $errors['discount_end'] = 'Discount end must be after its start.';
        }
        return [$s?->format('Y-m-d H:i:00'), $e?->format('Y-m-d H:i:00'), $errors];
    }

    /**
     * What the customer pays right now. Variant prices override product prices when set;
     * the discount window always comes from the product.
     *
     * @return array{price:string, retail:string, on_sale:bool}
     */
    public static function effective(array $product, ?array $variant = null, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $retail = $variant['retail_price'] ?? $product['retail_price'];
        $discount = $variant['discount_price'] ?? $product['discount_price'];

        $active = $discount !== null
            && ($product['discount_start'] === null || new \DateTimeImmutable($product['discount_start']) <= $now)
            && ($product['discount_end'] === null || new \DateTimeImmutable($product['discount_end']) >= $now)
            && self::cents((string) $discount) < self::cents((string) $retail);

        return [
            'price' => $active ? (string) $discount : (string) $retail,
            'retail' => (string) $retail,
            'on_sale' => $active,
        ];
    }

    /** Merge a variant's nullable prices over the product's, as strings ready for validate(). */
    public static function resolveVariant(array $product, array $variant): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = $variant[$f] ?? $product[$f];
        }
        return $out;
    }
}
