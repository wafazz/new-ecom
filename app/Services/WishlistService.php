<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;

final class WishlistService
{
    public const MAX = 200;

    public static function has(int $userId, int $productId): bool
    {
        return (bool) Database::query('SELECT 1 FROM wishlist_items WHERE user_id = ? AND product_id = ?', [$userId, $productId])->fetchColumn();
    }

    /** Ids of everything the member has saved (for filling the hearts on a listing in one query). @return array<int,true> */
    public static function ids(int $userId): array
    {
        return array_fill_keys(array_map('intval', Database::query('SELECT product_id FROM wishlist_items WHERE user_id = ?', [$userId])->fetchAll(\PDO::FETCH_COLUMN)), true);
    }

    /** @return bool|null true = added, false = removed, null = product unavailable / list full */
    public static function toggle(int $userId, int $productId): ?bool
    {
        if (self::has($userId, $productId)) {
            Database::query('DELETE FROM wishlist_items WHERE user_id = ? AND product_id = ?', [$userId, $productId]);
            return false;
        }
        $live = Database::query("SELECT 1 FROM products WHERE id = ? AND status = 'active' AND deleted_at IS NULL", [$productId])->fetchColumn();
        $count = (int) Database::query('SELECT COUNT(*) FROM wishlist_items WHERE user_id = ?', [$userId])->fetchColumn();
        if (!$live || $count >= self::MAX) {
            return null;
        }
        Database::query('INSERT IGNORE INTO wishlist_items (user_id, product_id) VALUES (?, ?)', [$userId, $productId]);
        return true;
    }

    /** Storefront-safe rows for the member's saved products that are still on sale, newest saved first. */
    public static function products(int $userId): array
    {
        $ids = array_map('intval', array_column(Database::query('SELECT product_id FROM wishlist_items WHERE user_id = ? ORDER BY created_at DESC, product_id DESC', [$userId])->fetchAll(), 'product_id'));
        return Product::publicByIds($ids);
    }
}
