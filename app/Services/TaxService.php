<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * SST on tax-inclusive prices. The rate is the product's own rate, or its category's rate, or 0. Postage is not taxed.
 * SST in an amount that already includes it is rate / (100 + rate) of that amount. Discounts reduce the amount first.
 */
final class TaxService
{
    public static function enabled(): bool
    {
        return Modules::enabled('tax');
    }

    public static function rateFor(int $productId): float
    {
        if (!self::enabled()) {
            return 0.0;
        }
        return (float) Database::query('SELECT COALESCE(p.tax_rate, c.tax_rate, 0) FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ?', [$productId])->fetchColumn();
    }

    /**
     * Sets tax_rate and tax_amount (cents) on each item, using the item's share of the discount. Returns the total SST.
     * @param array<int,array<string,mixed>> $items each with product_id, unit (cents) and qty
     */
    public static function split(array &$items, int $subtotal, int $discount): int
    {
        $total = 0;
        foreach ($items as &$it) {
            $rate = self::rateFor((int) $it['product_id']);
            $line = (int) $it['unit'] * (int) $it['qty'];
            $tax = self::taxOn($rate, $line, $subtotal, $discount);
            $it['tax_rate'] = $rate > 0 ? $rate : null;
            $it['tax_amount'] = $tax;
            $total += $tax;
        }
        unset($it);
        return $total;
    }

    /** The SST in the checkout for the cart's lines (cart rows have product_id and line_cents). Shown, not stored. */
    public static function forCart(array $cartItems, int $subtotal, int $discount): int
    {
        $total = 0;
        foreach ($cartItems as $l) {
            $total += self::taxOn(self::rateFor((int) $l['product_id']), (int) $l['line_cents'], $subtotal, $discount);
        }
        return $total;
    }

    private static function taxOn(float $rate, int $line, int $subtotal, int $discount): int
    {
        if ($rate <= 0 || $line <= 0) {
            return 0;
        }
        $share = $subtotal > 0 ? (int) round($discount * $line / $subtotal) : 0;
        $paid = max(0, $line - $share);
        return (int) round($paid * $rate / (100 + $rate));
    }

    /** Monthly SST for a window (cancelled orders left out). @return array<int,array{period:string, orders:int, sales:float, sst:float}> */
    public static function report(string $from, string $to): array
    {
        $rows = Database::query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') period, COUNT(*) orders, SUM(total) sales, SUM(tax_total) sst FROM orders
             WHERE status <> 'cancelled' AND created_at >= ? AND created_at < ? GROUP BY period ORDER BY period",
            [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
        )->fetchAll();
        return array_map(fn($r) => ['period' => (string) $r['period'], 'orders' => (int) $r['orders'], 'sales' => (float) $r['sales'], 'sst' => (float) $r['sst']], $rows);
    }
}
