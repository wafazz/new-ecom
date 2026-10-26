<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use App\Services\DigitalService;

final class Product extends Model
{
    protected static string $table = 'products';

    public static function findActive(int $id): ?array
    {
        return Database::query('SELECT * FROM products WHERE id = ? AND deleted_at IS NULL', [$id])->fetch() ?: null;
    }

    public const LOW_STOCK = 5;
    public const ADMIN_SORTS = ['newest' => 'Newest', 'name' => 'Name A-Z', 'price_desc' => 'Price: high to low', 'price_asc' => 'Price: low to high', 'stock_asc' => 'Stock: lowest first'];

    /**
     * Sellable stock: the variants' total when the product has variants, else its own stock.
     * Digital products: free license keys for "keys", and unlimited (DigitalService::UNLIMITED) for files and links.
     */
    private const STOCK = "(CASE WHEN p.product_type = 'digital' THEN
        (CASE WHEN p.digital_delivery = 'keys' THEN (SELECT COUNT(*) FROM product_license_keys k WHERE k.product_id = p.id AND k.order_item_id IS NULL) ELSE " . DigitalService::UNLIMITED . " END)
        WHEN EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL)
        THEN (SELECT COALESCE(SUM(v.stock), 0) FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL) ELSE p.stock END)";

    /**
     * Back-office list. $stock: '' | 'out' (nothing left) | 'low' (1..LOW_STOCK left).
     * @return array{rows: array, total: int}
     */
    public static function paginate(string $q, ?int $categoryId, string $status, int $page, int $perPage = 15, string $stock = '', string $sort = 'newest', ?int $brandId = null): array
    {
        $where = ['p.deleted_at IS NULL'];
        $args = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
            array_push($args, $like, $like);
        }
        if ($categoryId) {
            $ids = Category::withChildIds($categoryId); // a parent also shows its sub-categories' products
            $where[] = 'p.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($args, ...$ids);
        }
        if ($brandId) {
            $where[] = 'p.brand_id = ?';
            $args[] = $brandId;
        }
        if (in_array($status, ['draft', 'active'], true)) {
            $where[] = 'p.status = ?';
            $args[] = $status;
        }
        if ($stock === 'out') {
            $where[] = self::STOCK . ' <= 0';
        } elseif ($stock === 'low') {
            $where[] = self::STOCK . ' BETWEEN 1 AND ' . self::LOW_STOCK;
        }
        $w = implode(' AND ', $where);
        $order = match ($sort) {
            'name' => 'p.name ASC, p.id DESC',
            'price_desc' => 'p.retail_price DESC, p.id DESC',
            'price_asc' => 'p.retail_price ASC, p.id DESC',
            'stock_asc' => self::STOCK . ' ASC, p.id DESC',
            default => 'p.id DESC',
        };

        $total = (int) Database::query("SELECT COUNT(*) FROM products p WHERE {$w}", $args)->fetchColumn();
        $limit = max(1, $perPage);
        $offset = max(0, ($page - 1) * $limit);

        $rows = Database::query(
            "SELECT p.*, c.name AS category_name, b.name AS brand_name,
                (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL) AS variant_count,
                (SELECT COALESCE(SUM(v.stock), 0) FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL) AS variant_stock,
                (SELECT i.path FROM product_images i WHERE i.product_id = p.id ORDER BY i.is_primary DESC, i.id LIMIT 1) AS image
             FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id
             WHERE {$w} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}",
            $args
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /** Numbers for the filter chips (all products, ignoring the current search). */
    public static function counts(): array
    {
        $r = Database::query(
            "SELECT COUNT(*) total, COALESCE(SUM(p.status = 'active'), 0) active, COALESCE(SUM(p.status = 'draft'), 0) draft,
                    COALESCE(SUM(" . self::STOCK . " <= 0), 0) out_of_stock,
                    COALESCE(SUM(" . self::STOCK . " BETWEEN 1 AND " . self::LOW_STOCK . "), 0) low
             FROM products p WHERE p.deleted_at IS NULL"
        )->fetch();
        return array_map('intval', $r);
    }

    public static function softDelete(int $id): void
    {
        Database::query('UPDATE products SET deleted_at = NOW(), sku = NULL WHERE id = ?', [$id]);
        Database::query('UPDATE product_variants SET deleted_at = NOW(), sku = NULL WHERE product_id = ? AND deleted_at IS NULL', [$id]);
    }

    public static function images(int $productId): array
    {
        return Database::query('SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, position, id', [$productId])->fetchAll();
    }

    public const SORTS = ['newest' => 'Newest', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'name' => 'Name A-Z'];

    /**
     * Storefront listing. Selects only customer-safe columns (never cost / company prices).
     * Price sorting and the price filter use the product-level selling price (variant prices are not considered).
     *
     * $opts: min / max (selling price, "12.50"), sale (bool: discount running now), instock (bool), exclude (product id).
     *
     * @return array{rows: array, total: int}
     */
    public static function publicList(string $q, ?string $categorySlug, string $sort, int $page, int $perPage, ?string $brandSlug = null, array $opts = []): array
    {
        $now = date('Y-m-d H:i:s');
        $price = "CASE WHEN p.discount_price IS NOT NULL AND p.discount_price < p.retail_price
                        AND (p.discount_start IS NULL OR p.discount_start <= ?) AND (p.discount_end IS NULL OR p.discount_end >= ?)
                       THEN p.discount_price ELSE p.retail_price END";
        $where = ["p.deleted_at IS NULL", "p.status = 'active'"];
        $args = [];
        if ($q !== '') {
            $where[] = '(p.name LIKE ? OR b.name LIKE ? OR c.name LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like, $like);
        }
        if ($categorySlug !== null && $categorySlug !== '') {
            $where[] = '(c.slug = ? OR c.parent_id = (SELECT id FROM categories WHERE slug = ?))';
            array_push($args, $categorySlug, $categorySlug);
        }
        if ($brandSlug !== null && $brandSlug !== '') {
            $where[] = 'b.slug = ?';
            $args[] = $brandSlug;
        }
        foreach (['min' => '>=', 'max' => '<='] as $k => $op) {
            if (isset($opts[$k]) && $opts[$k] !== '' && $opts[$k] !== null) {
                $where[] = "({$price}) {$op} ?";
                array_push($args, $now, $now, (string) $opts[$k]);
            }
        }
        if (!empty($opts['sale'])) {
            [$flashSql, $flashArgs] = \App\Services\FlashSaleService::liveProductSql();
            $where[] = '((p.discount_price IS NOT NULL AND p.discount_price < p.retail_price AND (p.discount_start IS NULL OR p.discount_start <= ?) AND (p.discount_end IS NULL OR p.discount_end >= ?))
                OR p.id IN (' . $flashSql . '))';
            array_push($args, $now, $now, ...$flashArgs);
        }
        if (!empty($opts['instock'])) {
            $where[] = self::STOCK . ' > 0';
        }
        if (!empty($opts['exclude'])) {
            $where[] = 'p.id <> ?';
            $args[] = (int) $opts['exclude'];
        }
        $w = implode(' AND ', $where);

        $orderArgs = [];
        switch ($sort) {
            case 'price_asc':  $order = "{$price} ASC, p.id DESC";  $orderArgs = [$now, $now]; break;
            case 'price_desc': $order = "{$price} DESC, p.id DESC"; $orderArgs = [$now, $now]; break;
            case 'name':       $order = 'p.name ASC'; break;
            case 'relevance':  // name matches before brand / category matches (search suggestions)
                $order = '(p.name LIKE ?) DESC, p.name ASC';
                $orderArgs = ['%' . addcslashes($q, '%_\\') . '%'];
                break;
            default:           $order = 'p.id DESC';
        }

        $from = 'FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id';
        $total = (int) Database::query("SELECT COUNT(*) {$from} WHERE {$w}", $args)->fetchColumn();
        $limit = max(1, $perPage);
        $offset = max(0, ($page - 1) * $limit);

        $rows = Database::query(
            'SELECT ' . self::CARD_COLUMNS . " {$from} WHERE {$w} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}",
            [...$args, ...$orderArgs]
        )->fetchAll();

        return ['rows' => self::withDisplayPrices($rows), 'total' => $total];
    }

    /** What a product card needs: customer-safe columns, two images (the second shows on hover), counts for stock. */
    private const CARD_COLUMNS = "p.id, p.name, p.slug, p.stock, p.product_type, p.digital_delivery, p.retail_price, p.discount_price, p.discount_start, p.discount_end, p.created_at,
                    c.name AS category_name, b.name AS brand_name,
                    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL) AS variant_count,
                    (SELECT COALESCE(SUM(v.stock), 0) FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL) AS variant_stock,
                    (SELECT i.path FROM product_images i WHERE i.product_id = p.id ORDER BY i.is_primary DESC, i.position, i.id LIMIT 1) AS image,
                    (SELECT i.path FROM product_images i WHERE i.product_id = p.id ORDER BY i.is_primary DESC, i.position, i.id LIMIT 1 OFFSET 1) AS image2";

    /**
     * Storefront rows for specific products (active, not deleted), in the order of $ids.
     * $offers replaces the live flash offers (used by the admin preview); see FlashSaleService::attach().
     */
    public static function publicByIds(array $ids, ?array $offers = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::query(
            'SELECT ' . self::CARD_COLUMNS . " FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id
             WHERE p.deleted_at IS NULL AND p.status = 'active' AND p.id IN ({$in})",
            $ids
        )->fetchAll();
        $pos = array_flip($ids);
        usort($rows, fn($a, $b) => $pos[(int) $a['id']] <=> $pos[(int) $b['id']]);
        return self::withDisplayPrices($rows, $offers);
    }

    /** Products with a discount running right now (home page "On sale"). */
    public static function onSale(int $limit): array
    {
        return self::publicList('', null, 'newest', 1, $limit, null, ['sale' => true])['rows'];
    }

    /** Best sellers: most units in orders that were not cancelled. Empty until something has sold. */
    public static function popular(int $limit): array
    {
        $ids = Database::query(
            "SELECT oi.product_id FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE o.status <> 'cancelled' AND oi.product_id IS NOT NULL
             GROUP BY oi.product_id ORDER BY SUM(oi.qty) DESC, oi.product_id DESC LIMIT " . (int) ($limit * 3)
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_slice(self::publicByIds($ids), 0, $limit);
    }

    /** More from the same category (or its parent's family); falls back to the newest products. */
    public static function related(int $productId, ?int $categoryId, int $limit): array
    {
        if ($categoryId !== null) {
            $slug = Database::query('SELECT slug FROM categories WHERE id = ?', [$categoryId])->fetchColumn();
            $rows = $slug ? self::publicList('', (string) $slug, 'newest', 1, $limit, null, ['exclude' => $productId])['rows'] : [];
            if (count($rows) >= min($limit, 2)) {
                return $rows;
            }
        }
        return self::publicList('', null, 'newest', 1, $limit, null, ['exclude' => $productId])['rows'];
    }

    /** Top-level categories that have products, with a count and a picture (from one of their products) for the home page tiles. */
    public static function categoryTiles(int $limit): array
    {
        $own = "(p.category_id = c.id OR p.category_id IN (SELECT k.id FROM categories k WHERE k.parent_id = c.id))";
        return Database::query(
            "SELECT c.id, c.name, c.slug,
                    (SELECT COUNT(*) FROM products p WHERE p.status = 'active' AND p.deleted_at IS NULL AND {$own}) AS n,
                    (SELECT i.path FROM product_images i JOIN products p ON p.id = i.product_id
                      WHERE p.status = 'active' AND p.deleted_at IS NULL AND {$own}
                      ORDER BY i.is_primary DESC, p.id DESC, i.position LIMIT 1) AS image
             FROM categories c WHERE c.parent_id IS NULL HAVING n > 0 ORDER BY n DESC, c.name LIMIT " . (int) $limit
        )->fetchAll();
    }

    /** Adds price / retail / on_sale / from (bool: variants differ) / sold_out to each row. */
    private static function withDisplayPrices(array $rows, ?array $offers = null): array
    {
        $rows = \App\Services\FlashSaleService::attach($rows, 'id', $offers);
        $variants = [];
        if ($rows !== []) {
            $ids = array_map('intval', array_column($rows, 'id'));
            $in = implode(',', $ids); // ints only
            foreach (Database::query("SELECT product_id, retail_price, discount_price FROM product_variants WHERE product_id IN ({$in}) AND deleted_at IS NULL")->fetchAll() as $v) {
                $variants[$v['product_id']][] = $v;
            }
        }
        foreach ($rows as &$r) {
            $prices = [\App\Services\PricingService::effective($r)];
            foreach ($variants[$r['id']] ?? [] as $v) {
                $prices[] = \App\Services\PricingService::effective($r, $v);
            }
            usort($prices, fn($a, $b) => \App\Services\PricingService::cents($a['price']) <=> \App\Services\PricingService::cents($b['price']));
            if ($r['variant_count'] > 0) {
                array_shift($prices); // product-level price is not itself purchasable when variants exist
                $prices = $prices ?: [\App\Services\PricingService::effective($r)];
            }
            $low = $prices[0];
            $high = end($prices);
            $r['price'] = $low['price'];
            $r['retail'] = $low['retail'];
            $r['on_sale'] = $low['on_sale'];
            $r['from'] = \App\Services\PricingService::cents($low['price']) !== \App\Services\PricingService::cents($high['price']);
            $r['sold_out'] = DigitalService::isDigital($r)
                ? DigitalService::stock($r) <= 0
                : ($r['variant_count'] > 0 ? (int) $r['variant_stock'] : (int) $r['stock']) <= 0;
        }
        return $rows;
    }

    public static function publicBySlug(string $slug): ?array
    {
        $row = Database::query(
            "SELECT p.id, p.name, p.slug, p.sku, p.category_id, p.description, p.stock, p.product_type, p.digital_delivery, p.digital_expiry_days, p.digital_max_downloads, p.retail_price, p.discount_price, p.discount_start, p.discount_end, c.name AS category_name, c.slug AS category_slug, pc.name AS parent_name, pc.slug AS parent_slug, b.name AS brand_name, b.slug AS brand_slug, b.logo AS brand_logo
             FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN categories pc ON pc.id = c.parent_id LEFT JOIN brands b ON b.id = p.brand_id
             WHERE p.slug = ? AND p.status = 'active' AND p.deleted_at IS NULL",
            [$slug]
        )->fetch();
        return $row ?: null;
    }

    /** Categories that have active products (own or in a sub-category), parents first with children after. */
    public static function publicCategories(): array
    {
        return Category::tree(Database::query(
            "SELECT c.id, c.name, c.slug, c.parent_id, COUNT(p.id) AS n FROM categories c
             JOIN products p ON (p.category_id = c.id OR p.category_id IN (SELECT k.id FROM categories k WHERE k.parent_id = c.id))
                  AND p.status = 'active' AND p.deleted_at IS NULL
             GROUP BY c.id ORDER BY c.name"
        )->fetchAll());
    }

    /** Brands that have active products, with counts. */
    public static function publicBrands(): array
    {
        return Database::query(
            "SELECT b.name, b.slug, b.logo, COUNT(p.id) AS n FROM brands b
             JOIN products p ON p.brand_id = b.id AND p.status = 'active' AND p.deleted_at IS NULL
             GROUP BY b.id ORDER BY b.name"
        )->fetchAll();
    }
}
