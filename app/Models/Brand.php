<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Brand extends Model
{
    protected static string $table = 'brands';

    /** Every brand with its live product count. */
    public static function allWithCounts(): array
    {
        return Database::query(
            'SELECT b.*, (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.deleted_at IS NULL) AS product_count
             FROM brands b ORDER BY b.name'
        )->fetchAll();
    }

    /** For dropdowns. */
    public static function options(): array
    {
        return Database::query('SELECT id, name, slug FROM brands ORDER BY name')->fetchAll();
    }
}
