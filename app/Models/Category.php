<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/** Categories are one level deep: a category is top-level or the child of a top-level category. */
final class Category extends Model
{
    protected static string $table = 'categories';

    /** Every category with its direct product count, parent name and child count, parents first then their children. */
    public static function allWithCounts(): array
    {
        return self::tree(Database::query(
            'SELECT c.*, pc.name AS parent_name,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.deleted_at IS NULL) AS product_count,
                    (SELECT COUNT(*) FROM categories k WHERE k.parent_id = c.id) AS child_count
             FROM categories c LEFT JOIN categories pc ON pc.id = c.parent_id ORDER BY c.name'
        )->fetchAll());
    }

    /** For dropdowns: id, name, parent_id and a label such as "Home › Candles". */
    public static function options(): array
    {
        return self::tree(Database::query('SELECT id, name, slug, parent_id FROM categories ORDER BY name')->fetchAll());
    }

    /** Top-level categories (the only valid parents). */
    public static function parents(): array
    {
        return Database::query('SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name')->fetchAll();
    }

    /** The category id plus the ids of its children (for "show everything under this category"). */
    public static function withChildIds(int $id): array
    {
        $ids = Database::query('SELECT id FROM categories WHERE id = ? OR parent_id = ?', [$id, $id])->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('intval', $ids);
    }

    /**
     * Orders rows parent-first with children right after, adding `depth` and `label`.
     * A child whose parent row is missing from $rows is treated as top-level.
     */
    public static function tree(array $rows): array
    {
        $byParent = [];
        $names = [];
        foreach ($rows as $r) {
            $names[(int) $r['id']] = $r['name'];
        }
        foreach ($rows as $r) {
            $p = $r['parent_id'] !== null && isset($names[(int) $r['parent_id']]) ? (int) $r['parent_id'] : 0;
            $byParent[$p][] = $r;
        }
        $out = [];
        foreach ($byParent[0] ?? [] as $top) {
            $top['depth'] = 0;
            $top['label'] = $top['name'];
            $out[] = $top;
            foreach ($byParent[(int) $top['id']] ?? [] as $child) {
                $child['depth'] = 1;
                $child['label'] = $top['name'] . ' › ' . $child['name'];
                $out[] = $child;
            }
        }
        return $out;
    }
}
