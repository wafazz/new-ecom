<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Change the regular (retail) prices of many products by one percentage. Sale prices are not touched.
 * Variants that have their own retail price change too. Every change is recorded so a batch can be undone,
 * but an undo only restores a price that still holds the value this batch set.
 */
final class BulkPricingService
{
    public const MAX_ITEMS = 2000;
    public const MIN_PERCENT = -90;
    public const MAX_PERCENT = 200;

    /** @return array{type:string, id:int} type: all | category | brand */
    public static function scope(?string $type, int $id): array
    {
        return in_array($type, ['category', 'brand'], true) ? ['type' => $type, 'id' => $id] : ['type' => 'all', 'id' => 0];
    }

    /** @return string|null the reason the percentage is not allowed, or null when it is */
    public static function checkPercent(float $percent): ?string
    {
        if ($percent === 0.0) {
            return 'Enter a percentage other than zero.';
        }
        if ($percent < self::MIN_PERCENT || $percent > self::MAX_PERCENT) {
            return 'Enter a percentage from ' . self::MIN_PERCENT . ' to ' . self::MAX_PERCENT . '.';
        }
        return null;
    }

    /** The products in scope, with their current and new retail price. Nothing is changed. */
    public static function preview(array $scope, float $percent): array
    {
        $out = [];
        foreach (self::targets($scope, $percent) as $t) {
            $out[] = $t;
        }
        return $out;
    }

    /** @return array{batch:int, products:int, variants:int} */
    public static function apply(array $scope, float $percent, int $actorId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $targets = self::targets($scope, $percent);
            if (count(array_filter($targets, fn($t) => $t['variant_id'] === null)) > self::MAX_ITEMS) {
                throw new \DomainException('That is more than ' . self::MAX_ITEMS . ' products. Narrow the scope and try again.');
            }
            if ($targets === []) {
                throw new \DomainException('No prices would change.');
            }
            $products = count(array_unique(array_column($targets, 'product_id')));
            Database::query('INSERT INTO price_batches (scope_label, percent, item_count, actor_id) VALUES (?, ?, ?, ?)',
                [self::label($scope), $percent, $products, $actorId]);
            $batch = (int) Database::connection()->lastInsertId();
            $variants = 0;
            foreach ($targets as $t) {
                $table = $t['variant_id'] !== null ? 'product_variants' : 'products';
                $id = $t['variant_id'] ?? $t['product_id'];
                Database::query("UPDATE {$table} SET retail_price = ? WHERE id = ? AND retail_price = ?", [$t['new'], $id, $t['old']]);
                Database::query('INSERT INTO price_changes (batch_id, product_id, variant_id, old_price, new_price) VALUES (?, ?, ?, ?, ?)',
                    [$batch, $t['product_id'], $t['variant_id'], $t['old'], $t['new']]);
                $variants += $t['variant_id'] !== null ? 1 : 0;
            }
            AuditService::log($actorId, 'pricing.bulk', "batch {$batch}: " . self::label($scope) . " {$percent}% on {$products} product(s)");
            $pdo->commit();
            return ['batch' => $batch, 'products' => $products, 'variants' => $variants];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Undo a batch. Prices changed again since then are left as they are. @return array{restored:int, skipped:int} */
    public static function undo(int $batchId, int $actorId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $batch = Database::query('SELECT id, undone_at FROM price_batches WHERE id = ? FOR UPDATE', [$batchId])->fetch();
            if (!$batch) {
                throw new \DomainException('That price change does not exist.');
            }
            if ($batch['undone_at'] !== null) {
                throw new \DomainException('That price change was already undone.');
            }
            $restored = 0;
            $skipped = 0;
            foreach (Database::query('SELECT * FROM price_changes WHERE batch_id = ?', [$batchId])->fetchAll() as $c) {
                $table = $c['variant_id'] !== null ? 'product_variants' : 'products';
                $id = $c['variant_id'] ?? $c['product_id'];
                $n = Database::query("UPDATE {$table} SET retail_price = ? WHERE id = ? AND retail_price = ?", [$c['old_price'], $id, $c['new_price']])->rowCount();
                $n > 0 ? $restored++ : $skipped++;
            }
            Database::query('UPDATE price_batches SET undone_at = NOW() WHERE id = ?', [$batchId]);
            AuditService::log($actorId, 'pricing.undo', "batch {$batchId}: restored {$restored}, skipped {$skipped}");
            $pdo->commit();
            return ['restored' => $restored, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Recent batches, newest first. */
    public static function batches(int $limit = 15): array
    {
        return Database::query('SELECT b.*, u.name actor FROM price_batches b LEFT JOIN users u ON u.id = b.actor_id ORDER BY b.id DESC LIMIT ' . (int) $limit)->fetchAll();
    }

    public static function label(array $scope): string
    {
        if ($scope['type'] === 'category') {
            $n = Database::query('SELECT name FROM categories WHERE id = ?', [$scope['id']])->fetchColumn();
            return 'Category: ' . ($n !== false ? $n : '#' . $scope['id']);
        }
        if ($scope['type'] === 'brand') {
            $n = Database::query('SELECT name FROM brands WHERE id = ?', [$scope['id']])->fetchColumn();
            return 'Brand: ' . ($n !== false ? $n : '#' . $scope['id']);
        }
        return 'All products';
    }

    /**
     * Every price that would change. A product row, plus one row per variant that has its own retail price.
     * @return array<int,array{product_id:int, variant_id:?int, name:string, label:string, old:string, new:string}>
     */
    private static function targets(array $scope, float $percent): array
    {
        $where = ["p.deleted_at IS NULL", "p.status = 'active'", "p.retail_price IS NOT NULL"];
        $args = [];
        if ($scope['type'] === 'category') {
            $where[] = 'p.category_id IN (SELECT id FROM categories WHERE id = ? OR parent_id = ?)';
            array_push($args, $scope['id'], $scope['id']);
        } elseif ($scope['type'] === 'brand') {
            $where[] = 'p.brand_id = ?';
            $args[] = $scope['id'];
        }
        $rows = Database::query(
            'SELECT p.id, p.name, p.retail_price FROM products p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id',
            $args
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::row((int) $r['id'], null, (string) $r['name'], '', (string) $r['retail_price'], $percent);
        }
        if ($rows !== []) {
            $ids = array_map(fn($r) => (int) $r['id'], $rows);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $vars = Database::query(
                "SELECT v.id, v.product_id, v.retail_price, p.name FROM product_variants v JOIN products p ON p.id = v.product_id
                 WHERE v.product_id IN ({$in}) AND v.deleted_at IS NULL AND v.retail_price IS NOT NULL ORDER BY v.id",
                $ids
            )->fetchAll();
            foreach ($vars as $v) {
                $out[] = self::row((int) $v['product_id'], (int) $v['id'], (string) $v['name'], VariantService::label((int) $v['id']), (string) $v['retail_price'], $percent);
            }
        }
        return array_values(array_filter($out, fn($t) => $t['old'] !== $t['new']));
    }

    private static function row(int $productId, ?int $variantId, string $name, string $label, string $old, float $percent): array
    {
        $new = number_format(max(0.01, round((float) $old * (1 + $percent / 100), 2)), 2, '.', '');
        return ['product_id' => $productId, 'variant_id' => $variantId, 'name' => $name, 'label' => $label, 'old' => number_format((float) $old, 2, '.', ''), 'new' => $new];
    }
}
