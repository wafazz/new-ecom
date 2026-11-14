<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;

/**
 * Pre-orders for simple products. While a pre-order is open, the units a shopper can buy are the limit minus the units
 * sold, and physical stock is not used. When the goods arrive, stock is set to what arrived minus the units already sold,
 * and the pre-order closes.
 */
final class PreorderService
{
    public static function isPreorder(int $productId): bool
    {
        $limit = Database::query('SELECT preorder_limit FROM products WHERE id = ?', [$productId])->fetchColumn();
        return $limit !== false && $limit !== null;
    }

    /** Taking new pre-orders: the switch is on and the product has an open pre-order. */
    public static function accepting(int $productId): bool
    {
        return Modules::enabled('preorders') && self::isPreorder($productId);
    }

    /** Units a shopper can still buy: the pre-order's remaining units, or the physical stock when there is no pre-order. */
    public static function sellable(int $productId, int $physicalStock): int
    {
        $r = Database::query('SELECT preorder_limit, preorder_sold FROM products WHERE id = ?', [$productId])->fetch();
        if (!$r || $r['preorder_limit'] === null || !Modules::enabled('preorders')) {
            return $physicalStock;
        }
        return max(0, (int) $r['preorder_limit'] - (int) $r['preorder_sold']);
    }

    /** @return array{expected:?string, limit:int, sold:int, left:int}|null */
    public static function info(int $productId): ?array
    {
        $r = Database::query('SELECT preorder_expected, preorder_limit, preorder_sold FROM products WHERE id = ?', [$productId])->fetch();
        if (!$r || $r['preorder_limit'] === null) {
            return null;
        }
        $limit = (int) $r['preorder_limit'];
        $sold = (int) $r['preorder_sold'];
        return ['expected' => $r['preorder_expected'] !== null ? (string) $r['preorder_expected'] : null, 'limit' => $limit, 'sold' => $sold, 'left' => max(0, $limit - $sold)];
    }

    /** Takes pre-order units for an order line. Atomic against the limit, so two checkouts cannot oversell. */
    public static function reserve(int $productId, int $qty, int $orderId): void
    {
        $n = Database::query(
            'UPDATE products SET preorder_sold = preorder_sold + ? WHERE id = ? AND preorder_limit IS NOT NULL AND preorder_sold + ? <= preorder_limit',
            [$qty, $productId, $qty]
        )->rowCount();
        if ($n !== 1) {
            $left = self::info($productId)['left'] ?? 0;
            throw new \DomainException('Only ' . $left . ' left to pre-order for this item. Please lower the quantity.');
        }
    }

    /**
     * Gives back an order line's units. While the pre-order is open they return to the limit; after the goods arrived they
     * return to physical stock.
     */
    public static function release(int $productId, int $qty, string $reason, int $orderId, ?int $actorId): void
    {
        if (self::isPreorder($productId)) {
            Database::query('UPDATE products SET preorder_sold = GREATEST(preorder_sold - ?, 0) WHERE id = ?', [$qty, $productId]);
            return;
        }
        StockService::delta($productId, null, $qty, $reason, $orderId, $actorId);
    }

    /** @return string|null error */
    public static function open(int $productId, ?string $expected, int $limit, int $actorId): ?string
    {
        $product = Product::findActive($productId);
        if ($product === null || DigitalService::isDigital($product)) {
            return 'Choose a live physical product.';
        }
        if (Database::query('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND deleted_at IS NULL', [$productId])->fetchColumn() > 0) {
            return 'Pre-orders work on single products only, not products with options.';
        }
        if ($limit < 1 || $limit > 10000) {
            return 'The most units to pre-order is from 1 to 10,000.';
        }
        $date = null;
        if ($expected !== null && trim($expected) !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($expected));
            if (!$d || $d->format('Y-m-d') !== trim($expected)) {
                return 'Pick a valid expected date.';
            }
            $date = $d->format('Y-m-d');
        }
        $sold = (int) Database::query('SELECT preorder_sold FROM products WHERE id = ?', [$productId])->fetchColumn();
        if ($limit < $sold) {
            return "{$sold} unit(s) are already pre-ordered, so the limit cannot be lower than that.";
        }
        Database::query('UPDATE products SET preorder_expected = ?, preorder_limit = ? WHERE id = ?', [$date, $limit, $productId]);
        AuditService::log($actorId, 'preorder.opened', "product {$productId}: limit {$limit}, expected " . ($date ?? 'not set'));
        return null;
    }

    /** The goods arrived: stock becomes arrived minus the pre-orders already sold, and the pre-order closes. @return string|null */
    public static function arrive(int $productId, int $arrived, int $actorId): ?string
    {
        if (!self::isPreorder($productId)) {
            return 'This product is not in pre-order.';
        }
        if ($arrived < 1 || $arrived > StockService::MAX) {
            return 'Enter how many units arrived.';
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $sold = (int) Database::query('SELECT preorder_sold FROM products WHERE id = ? FOR UPDATE', [$productId])->fetchColumn();
            if ($arrived < $sold) {
                $pdo->rollBack();
                return "Only {$arrived} arrived, but {$sold} are pre-ordered. Receive at least {$sold}.";
            }
            $change = $arrived - $sold;
            if ($change !== 0) {
                StockService::delta($productId, null, $change, 'restock', null, $actorId, 'Pre-order stock arrived');
            }
            Database::query('UPDATE products SET preorder_limit = NULL, preorder_expected = NULL, preorder_sold = 0 WHERE id = ?', [$productId]);
            AuditService::log($actorId, 'preorder.arrived', "product {$productId}: {$arrived} arrived, {$sold} pre-ordered");
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> open pre-orders */
    public static function openList(): array
    {
        return Database::query('SELECT id, name, preorder_expected, preorder_limit, preorder_sold FROM products WHERE preorder_limit IS NOT NULL ORDER BY name')->fetchAll();
    }
}
