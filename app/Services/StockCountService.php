<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Stock count sheets. A count takes a snapshot of every physical product or option in a scope (all, or one category).
 * Counters enter what they see; the differences are then applied as "correction" stock changes, once per line.
 * Bundles have no stock of their own and are left out.
 */
final class StockCountService
{
    /** @return int count id */
    public static function create(string $title, ?int $categoryId, int $actorId): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $where = "p.deleted_at IS NULL AND p.status = 'active' AND p.product_type <> 'digital'";
            $args = [];
            $scope = 'All products';
            if ($categoryId !== null) {
                $where .= ' AND p.category_id IN (SELECT id FROM categories WHERE id = ? OR parent_id = ?)';
                array_push($args, $categoryId, $categoryId);
                $scope = 'Category: ' . (string) Database::query('SELECT name FROM categories WHERE id = ?', [$categoryId])->fetchColumn();
            }
            Database::query('INSERT INTO stock_counts (title, scope_label, created_by) VALUES (?, ?, ?)', [mb_substr($title, 0, 120), $scope, $actorId]);
            $id = (int) Database::connection()->lastInsertId();
            foreach (Database::query("SELECT p.id, p.name, p.sku, p.stock FROM products p WHERE {$where} ORDER BY p.name, p.id", $args)->fetchAll() as $p) {
                if (BundleService::isBundle((int) $p['id'])) {
                    continue;
                }
                $variants = Database::query('SELECT id, sku, stock FROM product_variants WHERE product_id = ? AND deleted_at IS NULL ORDER BY id', [(int) $p['id']])->fetchAll();
                if ($variants === []) {
                    self::line($id, (int) $p['id'], null, (string) $p['name'], $p['sku'] !== null ? (string) $p['sku'] : null, (int) $p['stock']);
                    continue;
                }
                foreach ($variants as $v) {
                    self::line($id, (int) $p['id'], (int) $v['id'], (string) $p['name'] . ' · ' . VariantService::label((int) $v['id']), $v['sku'] !== null ? (string) $v['sku'] : null, (int) $v['stock']);
                }
            }
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function line(int $countId, int $productId, ?int $variantId, string $label, ?string $sku, int $system): void
    {
        Database::query('INSERT INTO stock_count_lines (count_id, product_id, variant_id, label, sku, system_qty) VALUES (?, ?, ?, ?, ?, ?)', [$countId, $productId, $variantId, mb_substr($label, 0, 190), $sku, $system]);
    }

    /** Saves what the counter wrote. Blank means not counted yet. @param array<int,string> $counted line id => quantity */
    public static function saveCounts(int $countId, array $counted): ?string
    {
        $count = Database::query('SELECT status FROM stock_counts WHERE id = ?', [$countId])->fetch();
        if (!$count || $count['status'] !== 'open') {
            return 'This count is closed.';
        }
        foreach ($counted as $lineId => $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            if (!preg_match('/^\d{1,7}$/', $raw) || (int) $raw > StockService::MAX) {
                return 'Counts must be whole numbers, 0 or more.';
            }
            Database::query('UPDATE stock_count_lines SET counted_qty = ? WHERE id = ? AND count_id = ? AND applied = 0', [(int) $raw, (int) $lineId, $countId]);
        }
        return null;
    }

    /** Lines counted and different from the system figure, not yet applied. */
    public static function differences(int $countId): array
    {
        return Database::query(
            'SELECT * FROM stock_count_lines WHERE count_id = ? AND counted_qty IS NOT NULL AND counted_qty <> system_qty AND applied = 0 ORDER BY label',
            [$countId]
        )->fetchAll();
    }

    /** Applies the differences as "correction" changes. Each line once. @return array{applied:int, failed:array<int,string>} */
    public static function apply(int $countId, int $actorId): array
    {
        $applied = 0;
        $failed = [];
        foreach (self::differences($countId) as $l) {
            $r = StockService::adjust((int) $l['product_id'], $l['variant_id'] === null ? null : (int) $l['variant_id'], 'set', (string) $l['counted_qty'], 'correction', 'Stock count #' . $countId, $actorId);
            if ($r['ok'] ?? false) {
                Database::query('UPDATE stock_count_lines SET applied = 1 WHERE id = ?', [(int) $l['id']]);
                $applied++;
            } else {
                $failed[(int) $l['id']] = (string) ($r['error'] ?? 'Could not apply.');
            }
        }
        $left = (int) Database::query('SELECT COUNT(*) FROM stock_count_lines WHERE count_id = ? AND counted_qty IS NOT NULL AND counted_qty <> system_qty AND applied = 0', [$countId])->fetchColumn();
        if ($left === 0) {
            Database::query("UPDATE stock_counts SET status = 'applied', applied_at = NOW() WHERE id = ?", [$countId]);
        }
        AuditService::log($actorId, 'stock_count.applied', "count {$countId}: {$applied} line(s) applied");
        return ['applied' => $applied, 'failed' => $failed];
    }

    /** @return array<int,array<string,mixed>> */
    public static function listing(): array
    {
        return Database::query('SELECT c.*, (SELECT COUNT(*) FROM stock_count_lines l WHERE l.count_id = c.id) lines, (SELECT COUNT(*) FROM stock_count_lines l WHERE l.count_id = c.id AND l.counted_qty IS NOT NULL) counted FROM stock_counts c ORDER BY c.id DESC LIMIT 50')->fetchAll();
    }
}
