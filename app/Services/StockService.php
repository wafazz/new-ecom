<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Category;
use App\Models\Product;

/**
 * The single place stock quantities change. Every change locks the row, refuses to go below zero and
 * writes a stock_movements line (who, why, before/after). Sellable units are either a single product
 * (no active variations) or one variation of a product.
 */
final class StockService
{
    public const MAX = 1000000;
    /** What a person can pick when adjusting by hand. */
    public const REASONS = [
        'restock' => 'Restock / new delivery',
        'returned' => 'Returned by customer',
        'damaged' => 'Damaged or lost',
        'correction' => 'Stock count correction',
        'other' => 'Other',
    ];
    /** Written by the system, never offered in the form. */
    public const SYSTEM_REASONS = ['order' => 'Order placed', 'cancel' => 'Order cancelled', 'return' => 'Order returned', 'initial' => 'Opening stock'];
    public const SORTS = ['name' => 'Name A-Z', 'stock_asc' => 'Stock: lowest first', 'stock_desc' => 'Stock: highest first'];

    public static function reasonLabel(string $r): string
    {
        return self::REASONS[$r] ?? self::SYSTEM_REASONS[$r] ?? ucfirst($r);
    }

    /**
     * Change one item's stock by $delta (checkout uses -qty, cancellation +qty). Joins the caller's transaction.
     * @return array{before:int, after:int}
     * @throws \DomainException when the item is gone or the result would be negative
     */
    public static function delta(int $productId, ?int $variantId, int $delta, string $reason, ?int $orderId = null, ?int $actorId = null, ?string $note = null): array
    {
        return self::change($productId, $variantId, $delta, null, $reason, $orderId, $actorId, $note);
    }

    /**
     * A person's adjustment from the Stock Control page.
     * $mode: add | remove | set. Returns ['ok'=>bool, 'error'=>?string, 'before'=>int, 'after'=>int, 'changed'=>bool].
     */
    public static function adjust(int $productId, ?int $variantId, string $mode, string $rawQty, string $reason, ?string $note, ?int $actorId): array
    {
        $fail = fn(string $m) => ['ok' => false, 'error' => $m];
        if (!in_array($mode, ['add', 'remove', 'set'], true)) {
            return $fail('Choose add, remove or set.');
        }
        $rawQty = trim($rawQty);
        if (!preg_match('/^\d{1,7}$/', $rawQty) || (int) $rawQty > self::MAX) {
            return $fail('Quantity must be a whole number, 0 or more (up to ' . number_format(self::MAX) . ').');
        }
        $qty = (int) $rawQty;
        if ($mode !== 'set' && $qty < 1) {
            return $fail('Enter a quantity of 1 or more.');
        }
        if (!isset(self::REASONS[$reason])) {
            return $fail('Choose a reason.');
        }
        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > 255) {
            return $fail('The note is too long (255 characters max).');
        }
        $product = Product::findActive($productId);
        if ($product === null) {
            return $fail('That product no longer exists.');
        }
        $hasVariants = (int) Database::query('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND deleted_at IS NULL', [$productId])->fetchColumn() > 0;
        if ($hasVariants && $variantId === null) {
            return $fail('This product has variations: choose which one to adjust.');
        }
        if (!$hasVariants && $variantId !== null) {
            return $fail('That variation no longer exists.');
        }
        try {
            $r = self::change($productId, $variantId, $mode === 'set' ? null : ($mode === 'add' ? $qty : -$qty), $mode === 'set' ? $qty : null, $reason, null, $actorId, $note === '' ? null : $note);
        } catch (\DomainException $e) {
            return $fail($e->getMessage());
        }
        return ['ok' => true, 'error' => null, 'changed' => $r['before'] !== $r['after']] + $r;
    }

    /** Record a movement without changing stock, e.g. the opening stock of a seeded product. */
    public static function record(int $productId, ?int $variantId, int $before, int $after, string $reason, ?int $actorId = null, ?string $note = null): void
    {
        self::log($productId, $variantId, self::skuOf($productId, $variantId), $variantId ? VariantService::label($variantId) : null, $after - $before, $before, $after, $reason, null, $actorId, $note);
    }

    private static function skuOf(int $productId, ?int $variantId): ?string
    {
        $sku = $variantId !== null
            ? Database::query('SELECT sku FROM product_variants WHERE id = ?', [$variantId])->fetchColumn()
            : Database::query('SELECT sku FROM products WHERE id = ?', [$productId])->fetchColumn();
        return $sku === false || $sku === null ? null : (string) $sku;
    }

    private static function change(int $productId, ?int $variantId, ?int $delta, ?int $setTo, string $reason, ?int $orderId, ?int $actorId, ?string $note): array
    {
        $pdo = Database::connection();
        $own = !$pdo->inTransaction();
        $own && $pdo->beginTransaction();
        try {
            if ($variantId !== null) {
                $row = Database::query('SELECT stock, sku FROM product_variants WHERE id = ? AND product_id = ? FOR UPDATE', [$variantId, $productId])->fetch();
            } else {
                $row = Database::query('SELECT stock, sku FROM products WHERE id = ? FOR UPDATE', [$productId])->fetch();
            }
            if (!$row) {
                throw new \DomainException('That item no longer exists.');
            }
            $before = (int) $row['stock'];
            $after = $setTo ?? $before + (int) $delta;
            if ($after < 0) {
                throw new \DomainException($before > 0 ? "Only {$before} in stock: you cannot remove " . (-(int) $delta) . '.' : 'There is no stock to remove.');
            }
            if ($after > self::MAX) {
                throw new \DomainException('That would be more than ' . number_format(self::MAX) . ' units.');
            }
            if ($after !== $before) {
                Database::query($variantId !== null ? 'UPDATE product_variants SET stock = ? WHERE id = ?' : 'UPDATE products SET stock = ? WHERE id = ?', [$after, $variantId ?? $productId]);
                self::log($productId, $variantId, $row['sku'] !== null ? (string) $row['sku'] : null, $variantId ? VariantService::label($variantId) : null, $after - $before, $before, $after, $reason, $orderId, $actorId, $note);
            }
            $own && $pdo->commit();
            return ['before' => $before, 'after' => $after];
        } catch (\Throwable $e) {
            $own && $pdo->rollBack();
            throw $e;
        }
    }

    private static function log(int $productId, ?int $variantId, ?string $sku, ?string $label, int $change, int $before, int $after, string $reason, ?int $orderId, ?int $actorId, ?string $note): void
    {
        Database::query(
            'INSERT INTO stock_movements (product_id, variant_id, sku, label, change_qty, qty_before, qty_after, reason, note, order_id, actor_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$productId, $variantId, $sku, $label !== '' ? $label : null, $change, $before, $after, $reason, $note, $orderId, $actorId]
        );
    }

    // ---- reading -------------------------------------------------------------------------------------------

    /** One row per sellable unit: a single product, or each active variation of a product. */
    private const UNITS = "
        SELECT p.id AS product_id, NULL AS variant_id, p.name, NULL AS variation, p.sku, p.stock, p.status, p.category_id, p.brand_id,
               c.name AS category_name, b.name AS brand_name
          FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id
         WHERE p.deleted_at IS NULL AND p.product_type = 'physical' AND NOT EXISTS (SELECT 1 FROM product_variants x WHERE x.product_id = p.id AND x.deleted_at IS NULL)
        UNION ALL
        SELECT p.id, v.id, p.name,
               (SELECT GROUP_CONCAT(ov.value ORDER BY o.position, o.id SEPARATOR ' / ') FROM variant_option_values vov
                  JOIN product_option_values ov ON ov.id = vov.option_value_id JOIN product_options o ON o.id = ov.option_id WHERE vov.variant_id = v.id),
               v.sku, v.stock, p.status, p.category_id, p.brand_id, c.name, b.name
          FROM product_variants v JOIN products p ON p.id = v.product_id LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id
         WHERE v.deleted_at IS NULL AND p.deleted_at IS NULL";

    /** @return array{items:int, units:int, ok:int, low:int, out:int} */
    public static function summary(): array
    {
        $low = Product::LOW_STOCK;
        $r = Database::query(
            "SELECT COUNT(*) items, COALESCE(SUM(stock), 0) units, COALESCE(SUM(stock > {$low}), 0) ok,
                    COALESCE(SUM(stock BETWEEN 1 AND {$low}), 0) low, COALESCE(SUM(stock <= 0), 0) out_
               FROM (" . self::UNITS . ') t'
        )->fetch();
        return ['items' => (int) $r['items'], 'units' => (int) $r['units'], 'ok' => (int) $r['ok'], 'low' => (int) $r['low'], 'out' => (int) $r['out_']];
    }

    /** WHERE for the unit list (against the UNITS derived table `t`): returns [sql, args]. */
    private static function unitFilter(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(t.name LIKE ? OR t.sku LIKE ? OR t.variation LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        if (!empty($f['category'])) {
            $ids = Category::withChildIds((int) $f['category']);
            $where[] = 't.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($args, ...$ids);
        }
        if (!empty($f['brand'])) {
            $where[] = 't.brand_id = ?';
            $args[] = (int) $f['brand'];
        }
        if (!empty($f['product'])) {
            $where[] = 't.product_id = ?';
            $args[] = (int) $f['product'];
        }
        $low = Product::LOW_STOCK;
        $where[] = match ($f['level'] ?? '') {
            'out' => 't.stock <= 0',
            'low' => "t.stock BETWEEN 1 AND {$low}",
            'ok' => "t.stock > {$low}",
            default => '1=1',
        };
        return [implode(' AND ', $where), $args];
    }

    /**
     * The Stock Control list, one entry per PRODUCT. A product with variations is one group holding all of its
     * variations (the total is theirs summed); a product without variations is a group of one. A product is listed
     * when at least one of its units matches the filters; each unit says whether it matched itself.
     *
     * @param array{q?:string, category?:?int, brand?:?int, level?:string, product?:?int, sort?:string} $f
     * @return array{groups: array, total: int} total counts products
     */
    public static function items(array $f, int $page, int $perPage = 25): array
    {
        [$w, $args] = self::unitFilter($f);
        $order = match ($f['sort'] ?? 'name') {
            'stock_asc' => 'MIN(t.stock) ASC, t.name ASC, t.product_id ASC',   // needs attention first: the emptiest variation decides
            'stock_desc' => 'SUM(t.stock) DESC, t.name ASC, t.product_id ASC',
            default => 't.name ASC, t.product_id ASC',
        };
        $total = (int) Database::query('SELECT COUNT(DISTINCT t.product_id) FROM (' . self::UNITS . ") t WHERE {$w}", $args)->fetchColumn();
        $limit = max(1, $perPage);
        $offset = max(0, ($page - 1) * $limit);
        $ids = array_map('intval', Database::query(
            'SELECT t.product_id FROM (' . self::UNITS . ") t WHERE {$w} GROUP BY t.product_id, t.name ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}",
            $args
        )->fetchAll(\PDO::FETCH_COLUMN));
        if ($ids === []) {
            return ['groups' => [], 'total' => $total];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $units = Database::query('SELECT t.* FROM (' . self::UNITS . ") t WHERE t.product_id IN ({$in}) ORDER BY t.name, t.variation, t.variant_id", $ids)->fetchAll();
        $matched = [];
        foreach (Database::query('SELECT t.product_id, COALESCE(t.variant_id, 0) AS k FROM (' . self::UNITS . ") t WHERE {$w} AND t.product_id IN ({$in})", [...$args, ...$ids])->fetchAll() as $m) {
            $matched[(int) $m['product_id'] . ':' . (int) $m['k']] = true;
        }

        $low = Product::LOW_STOCK;
        $byProduct = [];
        foreach ($units as $u) {
            $u['stock'] = (int) $u['stock'];
            $u['match'] = isset($matched[(int) $u['product_id'] . ':' . (int) ($u['variant_id'] ?? 0)]);
            $byProduct[(int) $u['product_id']][] = $u;
        }
        $groups = [];
        foreach ($ids as $pid) {
            $us = $byProduct[$pid] ?? [];
            if ($us === []) {
                continue;
            }
            $first = $us[0];
            $single = count($us) === 1 && $first['variant_id'] === null;
            $groups[] = [
                'product_id' => $pid, 'name' => $first['name'], 'status' => $first['status'],
                'category_name' => $first['category_name'], 'brand_name' => $first['brand_name'],
                'single' => $single, 'sku' => $single ? $first['sku'] : null,
                'stock' => (int) array_sum(array_column($us, 'stock')),
                'out' => count(array_filter($us, fn($u) => $u['stock'] <= 0)),
                'low' => count(array_filter($us, fn($u) => $u['stock'] > 0 && $u['stock'] <= $low)),
                'variations' => $single ? 0 : count($us),
                'matches' => count(array_filter($us, fn($u) => $u['match'])),
                'units' => $us,
            ];
        }
        return ['groups' => $groups, 'total' => $total];
    }

    /** One sellable unit, for the adjust form / history header. */
    public static function unit(int $productId, ?int $variantId): ?array
    {
        $r = Database::query('SELECT t.* FROM (' . self::UNITS . ') t WHERE t.product_id = ? AND ' . ($variantId === null ? 't.variant_id IS NULL' : 't.variant_id = ?'), $variantId === null ? [$productId] : [$productId, $variantId])->fetch();
        return $r ?: null;
    }

    /** WHERE for stock_movements `m` (joined to products `p`): returns [sql, args]. */
    private static function movementFilter(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        if (!empty($f['product'])) {
            $where[] = 'm.product_id = ?';
            $args[] = (int) $f['product'];
            if (!empty($f['variant'])) {
                $where[] = 'm.variant_id = ?';
                $args[] = (int) $f['variant'];
            }
        }
        $reason = (string) ($f['reason'] ?? '');
        if ($reason === 'manual') {
            $where[] = "m.reason NOT IN ('order', 'cancel', 'initial')";
        } elseif (isset(self::REASONS[$reason]) || isset(self::SYSTEM_REASONS[$reason])) {
            $where[] = 'm.reason = ?';
            $args[] = $reason;
        }
        $dir = (string) ($f['dir'] ?? '');
        if ($dir === 'in') {
            $where[] = 'm.change_qty > 0';
        } elseif ($dir === 'out') {
            $where[] = 'm.change_qty < 0';
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(p.name LIKE ? OR m.sku LIKE ? OR m.label LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        if (!empty($f['from'])) {
            $where[] = 'm.created_at >= ?';
            $args[] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'm.created_at < ?';
            $args[] = (new \DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        $actor = (string) ($f['actor'] ?? '');
        if ($actor === 'system') {
            $where[] = 'm.actor_id IS NULL';
        } elseif (ctype_digit($actor) && (int) $actor > 0) {
            $where[] = 'm.actor_id = ?';
            $args[] = (int) $actor;
        }
        return [implode(' AND ', $where), $args];
    }

    private const MOVEMENT_FROM = 'FROM stock_movements m LEFT JOIN products p ON p.id = m.product_id LEFT JOIN users u ON u.id = m.actor_id LEFT JOIN orders o ON o.id = m.order_id';

    /**
     * The movement log, newest first. Filters: product, variant, reason ('manual' = hand adjustments), dir (in|out),
     * q (product / SKU / variation), from / to (Y-m-d, inclusive), actor (user id or 'system').
     * @return array{rows: array, total: int}
     */
    public static function history(array $f, int $page, int $perPage = 30): array
    {
        [$w, $args] = self::movementFilter($f);
        $total = (int) Database::query('SELECT COUNT(*) ' . self::MOVEMENT_FROM . " WHERE {$w}", $args)->fetchColumn();
        $limit = max(1, $perPage);
        $offset = max(0, ($page - 1) * $limit);
        $rows = Database::query(
            'SELECT m.*, p.name AS product_name, u.name AS actor_name, o.order_no ' . self::MOVEMENT_FROM . " WHERE {$w} ORDER BY m.id DESC LIMIT {$limit} OFFSET {$offset}",
            $args
        )->fetchAll();
        return ['rows' => $rows, 'total' => $total];
    }

    /** Totals for the same filters as history(): movements, units in, units out, net. @return array{count:int, in:int, out:int, net:int} */
    public static function movementSummary(array $f): array
    {
        [$w, $args] = self::movementFilter($f);
        $r = Database::query(
            'SELECT COUNT(*) c, COALESCE(SUM(CASE WHEN m.change_qty > 0 THEN m.change_qty END), 0) i, COALESCE(SUM(CASE WHEN m.change_qty < 0 THEN -m.change_qty END), 0) o ' . self::MOVEMENT_FROM . " WHERE {$w}",
            $args
        )->fetch();
        return ['count' => (int) $r['c'], 'in' => (int) $r['i'], 'out' => (int) $r['o'], 'net' => (int) $r['i'] - (int) $r['o']];
    }

    /** People who made stock changes, for the "Changed by" filter. @return array<int,array{id:int,name:string}> */
    public static function actors(): array
    {
        return Database::query('SELECT DISTINCT u.id, u.name FROM stock_movements m JOIN users u ON u.id = m.actor_id ORDER BY u.name')->fetchAll();
    }
}
