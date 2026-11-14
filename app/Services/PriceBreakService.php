<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;

/**
 * Quantity price breaks. The unit price drops once the total of a product in the cart reaches a break. A break only
 * applies when it is lower than the price the shopper would otherwise pay (a sale price included), so breaks never raise a price.
 * The cart and checkout both use unitFor(), so the charge matches what the cart shows.
 */
final class PriceBreakService
{
    public const MAX_BREAKS = 5;
    public const MAX_QTY = 999;

    /** @return array<int,array{min_qty:int, unit_price:float}> ordered by quantity */
    public static function breaksFor(int $productId): array
    {
        return array_map(
            fn($r) => ['min_qty' => (int) $r['min_qty'], 'unit_price' => (float) $r['unit_price']],
            Database::query('SELECT min_qty, unit_price FROM product_price_breaks WHERE product_id = ? ORDER BY min_qty', [$productId])->fetchAll()
        );
    }

    /** The unit price in cents for a total quantity of this product. $unitCents is the price without breaks. */
    public static function unitFor(int $productId, int $qty, int $unitCents): int
    {
        if ($qty < 2 || !Modules::enabled('quantity_breaks')) {
            return $unitCents;
        }
        $best = $unitCents;
        foreach (self::breaksFor($productId) as $b) {
            if ($qty >= $b['min_qty']) {
                $c = PricingService::cents(number_format($b['unit_price'], 2, '.', ''));
                if ($c < $best) {
                    $best = $c;
                }
            }
        }
        return $best;
    }

    /**
     * Replaces a product's breaks. Rows: [min_qty, unit_price]. A break must be cheaper than the product's price now.
     * @param array<int,array{min_qty:string, unit_price:string}> $rows
     * @return string|null error
     */
    public static function save(int $productId, array $rows, int $actorId): ?string
    {
        $product = Product::findActive($productId);
        if ($product === null || DigitalService::isDigital($product)) {
            return 'Choose a live product (digital products cannot have breaks).';
        }
        $now = (float) PricingService::effective($product)['price'];
        $clean = [];
        $seen = [];
        foreach ($rows as $r) {
            $min = trim((string) ($r['min_qty'] ?? ''));
            $price = trim((string) ($r['unit_price'] ?? ''));
            if ($min === '' && $price === '') {
                continue;
            }
            if (!preg_match('/^\d{1,3}$/', $min) || (int) $min < 2 || (int) $min > self::MAX_QTY) {
                return 'Each break needs a quantity from 2 to ' . self::MAX_QTY . '.';
            }
            if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $price) || (float) $price < 0.01) {
                return 'Each break needs a unit price in RM, for example 8.00.';
            }
            if ((float) $price >= $now) {
                return 'A break price must be lower than the current price of RM' . number_format($now, 2) . '.';
            }
            if (isset($seen[(int) $min])) {
                return 'Each quantity can only appear once.';
            }
            $seen[(int) $min] = true;
            $clean[] = ['min_qty' => (int) $min, 'unit_price' => number_format((float) $price, 2, '.', '')];
        }
        if (count($clean) > self::MAX_BREAKS) {
            return 'At most ' . self::MAX_BREAKS . ' breaks per product.';
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::query('DELETE FROM product_price_breaks WHERE product_id = ?', [$productId]);
            foreach ($clean as $c) {
                Database::query('INSERT INTO product_price_breaks (product_id, min_qty, unit_price) VALUES (?, ?, ?)', [$productId, $c['min_qty'], $c['unit_price']]);
            }
            AuditService::log($actorId, 'price_break.saved', "product {$productId}: " . count($clean) . ' break(s)');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return null;
    }
}
