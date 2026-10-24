<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;

/** Status switch and bulk actions from the products list (stock lives in StockService). Each returns ['ok' => bool, 'error' => ?string, ...]. */
final class ProductAdminService
{
    public const MAX_BULK = 100;
    public const ACTIONS = ['activate', 'draft', 'delete'];

    private static function live(int $id): ?array
    {
        return Product::findActive($id);
    }

    public static function setStatus(int $id, string $status): array
    {
        if (self::live($id) === null) {
            return ['ok' => false, 'error' => 'Product not found.'];
        }
        if (!in_array($status, ['active', 'draft'], true)) {
            return ['ok' => false, 'error' => 'Unknown status.'];
        }
        Product::update($id, ['status' => $status]);
        return ['ok' => true, 'status' => $status];
    }

    /** @param mixed $ids user input (array of ids) */
    public static function bulk(string $action, mixed $ids): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return ['ok' => false, 'error' => 'Unknown action.'];
        }
        $clean = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? array_filter($ids, 'is_scalar') : []), fn($i) => $i > 0)));
        if ($clean === []) {
            return ['ok' => false, 'error' => 'Select at least one product.'];
        }
        if (count($clean) > self::MAX_BULK) {
            return ['ok' => false, 'error' => 'Select at most ' . self::MAX_BULK . ' products at a time.'];
        }
        $in = implode(',', array_fill(0, count($clean), '?'));
        $live = array_map('intval', Database::query("SELECT id FROM products WHERE deleted_at IS NULL AND id IN ({$in})", $clean)->fetchAll(\PDO::FETCH_COLUMN));
        if ($live === []) {
            return ['ok' => false, 'error' => 'Those products no longer exist.'];
        }
        if ($action === 'delete') {
            foreach ($live as $id) {
                Product::softDelete($id);
            }
        } else {
            $in2 = implode(',', array_fill(0, count($live), '?'));
            Database::query("UPDATE products SET status = ? WHERE id IN ({$in2})", [$action === 'activate' ? 'active' : 'draft', ...$live]);
        }
        return ['ok' => true, 'count' => count($live)];
    }
}
