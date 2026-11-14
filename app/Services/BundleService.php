<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;

/**
 * Bundles. A bundle's own stock is always the number of bundles the parts can make, so it is recalculated whenever a
 * part's stock changes. Selling, cancelling and returning a bundle move stock on each part, never on the bundle.
 */
final class BundleService
{
    public const MAX_PARTS = 10;
    public const MAX_QTY = 50;

    public static function isBundle(int $productId): bool
    {
        return (int) Database::query('SELECT COUNT(*) FROM bundle_items WHERE bundle_product_id = ?', [$productId])->fetchColumn() > 0;
    }

    /** @return array<int,array{part_product_id:int, qty:int, name:string, stock:int, usable:bool}> */
    public static function parts(int $bundleId): array
    {
        $rows = Database::query(
            "SELECT bi.part_product_id, bi.qty, p.name, p.stock, (p.status = 'active' AND p.deleted_at IS NULL) usable
             FROM bundle_items bi JOIN products p ON p.id = bi.part_product_id WHERE bi.bundle_product_id = ? ORDER BY bi.id",
            [$bundleId]
        )->fetchAll();
        return array_map(fn($r) => [
            'part_product_id' => (int) $r['part_product_id'], 'qty' => (int) $r['qty'], 'name' => (string) $r['name'],
            'stock' => (int) $r['stock'], 'usable' => (int) $r['usable'] === 1,
        ], $rows);
    }

    /** Sets the bundle's stock from its parts and returns it. A part that is inactive or deleted makes none. */
    public static function refresh(int $bundleId): int
    {
        $parts = self::parts($bundleId);
        $stock = 0;
        if ($parts !== []) {
            $counts = array_map(fn($p) => $p['usable'] ? intdiv($p['stock'], max(1, $p['qty'])) : 0, $parts);
            $stock = min($counts);
        }
        Database::query('UPDATE products SET stock = ? WHERE id = ?', [$stock, $bundleId]);
        return $stock;
    }

    /** A part's stock changed: refresh every bundle it is in. */
    public static function refreshFor(int $partProductId): void
    {
        foreach (Database::query('SELECT DISTINCT bundle_product_id FROM bundle_items WHERE part_product_id = ?', [$partProductId])->fetchAll(\PDO::FETCH_COLUMN) as $bundleId) {
            self::refresh((int) $bundleId);
        }
    }

    /**
     * Sell (negative) or put back (positive) a line. A bundle moves stock on each part; anything else is a normal change.
     */
    public static function adjustLine(int $productId, ?int $variantId, int $signed, string $reason, ?int $orderId, ?int $actorId): void
    {
        if ($variantId === null && self::isBundle($productId)) {
            foreach (self::parts($productId) as $p) {
                StockService::delta($p['part_product_id'], null, $signed * $p['qty'], $reason, $orderId, $actorId);
            }
            return;
        }
        StockService::delta($productId, $variantId, $signed, $reason, $orderId, $actorId);
    }

    /**
     * Replace a bundle's parts. Each part must be a live, single-option, non-digital product; a bundle cannot hold a bundle.
     * @param array<int,array{product_id:int, qty:int}> $parts
     * @return string|null error message, or null when saved
     */
    public static function setParts(int $bundleId, array $parts, int $actorId): ?string
    {
        $bundle = Product::findActive($bundleId);
        if ($bundle === null) {
            return 'Choose a live product to be the bundle.';
        }
        if (Database::query('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND deleted_at IS NULL', [$bundleId])->fetchColumn() > 0) {
            return 'A bundle must be a single product, not one with options.';
        }
        if ($parts === [] || count($parts) > self::MAX_PARTS) {
            return 'A bundle needs 1 to ' . self::MAX_PARTS . ' items.';
        }
        $seen = [];
        foreach ($parts as $p) {
            $pid = (int) $p['product_id'];
            $qty = (int) $p['qty'];
            if ($pid === $bundleId) {
                return 'A bundle cannot contain itself.';
            }
            if (isset($seen[$pid])) {
                return 'The same item is listed twice.';
            }
            $seen[$pid] = true;
            if ($qty < 1 || $qty > self::MAX_QTY) {
                return 'Each quantity must be from 1 to ' . self::MAX_QTY . '.';
            }
            $part = Product::findActive($pid);
            if ($part === null) {
                return 'One of the items is not a live product.';
            }
            if (DigitalService::isDigital($part) || self::isBundle($pid)) {
                return "\"{$part['name']}\" cannot go in a bundle: digital items and bundles are not allowed.";
            }
            if (Database::query('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND deleted_at IS NULL', [$pid])->fetchColumn() > 0) {
                return "\"{$part['name']}\" has options. Only single products can go in a bundle.";
            }
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::query('DELETE FROM bundle_items WHERE bundle_product_id = ?', [$bundleId]);
            foreach ($parts as $p) {
                Database::query('INSERT INTO bundle_items (bundle_product_id, part_product_id, qty) VALUES (?, ?, ?)', [$bundleId, (int) $p['product_id'], (int) $p['qty']]);
            }
            $stock = self::refresh($bundleId);
            AuditService::log($actorId, 'bundle.saved', "bundle {$bundleId}: " . count($parts) . " item(s), {$stock} made");
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return null;
    }

    /** Every bundle with its parts, for the admin list. @return array<int,array{id:int, name:string, stock:int, parts:array}> */
    public static function listing(): array
    {
        $out = [];
        foreach (Database::query('SELECT DISTINCT p.id, p.name, p.stock FROM bundle_items bi JOIN products p ON p.id = bi.bundle_product_id ORDER BY p.name')->fetchAll() as $b) {
            $out[] = ['id' => (int) $b['id'], 'name' => (string) $b['name'], 'stock' => (int) $b['stock'], 'parts' => self::parts((int) $b['id'])];
        }
        return $out;
    }
}
