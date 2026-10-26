<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;

/**
 * Flash sales: a named timeframe with products that take a percentage off while it runs.
 * A product can sit in several timeframes; where they overlap, the bigger percentage wins.
 *
 * Prices are applied by attach(): it adds flash_percent / flash_ends_at / flash_name to product rows,
 * and PricingService::effective() takes the sale off whatever the normal price is.
 */
final class FlashSaleService
{
    public const MAX_PERCENT = 90;

    /** Turn the form's fields into a saved-ready sale. @return array{errors: array<string,string>, sale?: array, items?: array} */
    public static function validate(array $in): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Give the sale a name (up to 120 characters).';
        }

        [$start, $end, $windowErrors] = PricingService::window((string) ($in['starts_at'] ?? ''), (string) ($in['ends_at'] ?? ''));
        foreach ($windowErrors as $field => $message) {
            $errors[$field === 'discount_start' ? 'starts_at' : 'ends_at'] = $field === 'discount_end'
                ? 'The end must be after the start.'
                : 'Invalid date and time.';
        }
        if ($start === null && !isset($errors['starts_at'])) {
            $errors['starts_at'] = 'Set when the sale starts.';
        }
        if ($end === null && !isset($errors['ends_at'])) {
            $errors['ends_at'] = 'Set when the sale ends.';
        }

        $items = self::items($in['items'] ?? []);
        $rowErrors = self::itemErrors($in['items'] ?? []);
        foreach ($rowErrors as $pid => $message) {
            $errors["item_{$pid}"] = $message;
        }
        if ($items === [] && $rowErrors === []) {
            $errors['items'] = 'Pick at least one product and give it a percentage.';
        }

        return [
            'errors' => $errors,
            'sale' => ['name' => $name, 'starts_at' => $start, 'ends_at' => $end],
            'items' => $items,
        ];
    }

    /**
     * Valid selections only: [product_id => percent string like "25.00"]. A row counts when its box is ticked.
     * @return array<int,string>
     */
    public static function items(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $pid => $row) {
            if (!is_array($row) || empty($row['on'])) {
                continue;
            }
            $percent = trim((string) ($row['percent'] ?? ''));
            if (self::validPercent($percent) && ctype_digit((string) $pid)) {
                $out[(int) $pid] = self::normalisePercent($percent);
            }
        }
        return $out;
    }

    /** @return array<int,string> product id => why its row was refused */
    private static function itemErrors(mixed $raw): array
    {
        $errors = [];
        foreach (is_array($raw) ? $raw : [] as $pid => $row) {
            if (!is_array($row) || empty($row['on'])) {
                continue;
            }
            if (!self::validPercent(trim((string) ($row['percent'] ?? '')))) {
                $errors[(int) $pid] = 'Percentage must be between 1 and ' . self::MAX_PERCENT . ' (up to two decimals).';
            }
        }
        return $errors;
    }

    private static function validPercent(string $v): bool
    {
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $v)) {
            return false;
        }
        $n = (float) $v;
        return $n > 0 && $n <= self::MAX_PERCENT;
    }

    private static function normalisePercent(string $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    /** Products an admin can put in a sale: live products, plus any already in this sale. */
    public static function selectable(int $saleId): array
    {
        return Database::query(
            "SELECT p.id, p.name, p.sku, p.status, p.retail_price, p.discount_price, p.discount_start, p.discount_end,
                    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL) AS variant_count
             FROM products p
             WHERE p.deleted_at IS NULL AND (p.status = 'active' OR p.id IN (SELECT product_id FROM flash_sale_products WHERE flash_sale_id = ?))
             ORDER BY p.name, p.id",
            [$saleId]
        )->fetchAll();
    }

    /** Selected products of a saved sale, keyed by product id: ['percent' => '25.00']. */
    public static function itemsOf(int $saleId): array
    {
        $out = [];
        foreach (Database::query('SELECT product_id, percent_off FROM flash_sale_products WHERE flash_sale_id = ?', [$saleId])->fetchAll() as $r) {
            $out[(int) $r['product_id']] = ['percent' => self::normalisePercent((string) $r['percent_off'])];
        }
        return $out;
    }

    /** Create or update a sale and its product list in one transaction. @return int the sale id */
    public static function save(?int $id, array $sale, array $items): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                Database::query('INSERT INTO flash_sales (name, starts_at, ends_at, active) VALUES (?, ?, ?, 1)', [$sale['name'], $sale['starts_at'], $sale['ends_at']]);
                $id = (int) $pdo->lastInsertId();
            } else {
                Database::query('UPDATE flash_sales SET name = ?, starts_at = ?, ends_at = ?, updated_at = NOW() WHERE id = ?', [$sale['name'], $sale['starts_at'], $sale['ends_at'], $id]);
                Database::query('DELETE FROM flash_sale_products WHERE flash_sale_id = ?', [$id]);
            }
            foreach ($items as $pid => $percent) {
                Database::query('INSERT INTO flash_sale_products (flash_sale_id, product_id, percent_off) VALUES (?, ?, ?)', [$id, $pid, $percent]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::query('UPDATE flash_sales SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    public static function delete(int $id): void
    {
        Database::query('DELETE FROM flash_sales WHERE id = ?', [$id]);
    }

    public static function find(int $id): ?array
    {
        return Database::query('SELECT * FROM flash_sales WHERE id = ?', [$id])->fetch() ?: null;
    }

    /** Every sale with its product count and a status word: Off, Scheduled, Live or Ended. */
    public static function listing(): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = Database::query(
            'SELECT fs.*, (SELECT COUNT(*) FROM flash_sale_products fi WHERE fi.flash_sale_id = fs.id) AS product_count
             FROM flash_sales fs ORDER BY fs.starts_at DESC, fs.id DESC'
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['status'] = match (true) {
                (int) $r['active'] !== 1 => 'off',
                $r['ends_at'] <= $now => 'ended',
                $r['starts_at'] > $now => 'scheduled',
                default => 'live',
            };
        }
        unset($r);
        return $rows;
    }

    /**
     * Best live offer per product id, for the given products. Pass $offers to use a fixed set instead of the database
     * (the admin live preview does this with the unsaved form).
     *
     * @param  array<int,array{percent:string, ends_at:string, name:string}>|null $offers
     */
    public static function attach(array $rows, string $key = 'id', ?array $offers = null): array
    {
        if ($offers === null) {
            $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int) ($r[$key] ?? 0), $rows))));
            $offers = $ids === [] ? [] : self::offersFor($ids);
        }
        foreach ($rows as &$r) {
            $o = $offers[(int) ($r[$key] ?? 0)] ?? null;
            $r['flash_percent'] = $o['percent'] ?? null;
            $r['flash_ends_at'] = $o['ends_at'] ?? null;
            $r['flash_name'] = $o['name'] ?? null;
        }
        unset($r);
        return $rows;
    }

    /** Live offers for the given product ids: [product_id => ['percent','ends_at','name']], the biggest percentage first. */
    public static function offersFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $now = date('Y-m-d H:i:s');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        $rows = Database::query(
            "SELECT fi.product_id, fi.percent_off, fs.name, fs.ends_at
             FROM flash_sale_products fi JOIN flash_sales fs ON fs.id = fi.flash_sale_id
             WHERE fs.active = 1 AND fs.starts_at <= ? AND fs.ends_at > ? AND fi.product_id IN ({$in})
             ORDER BY fi.percent_off DESC, fs.ends_at ASC",
            [$now, $now, ...array_map('intval', $ids)]
        )->fetchAll();
        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            if (!isset($out[$pid])) { // rows are sorted best first
                $out[$pid] = ['percent' => self::normalisePercent((string) $r['percent_off']), 'ends_at' => (string) $r['ends_at'], 'name' => (string) $r['name']];
            }
        }
        return $out;
    }

    /** Product ids with a flash offer running now, as a sub-select fragment for storefront queries. @return array{0: string, 1: string[]} */
    public static function liveProductSql(): array
    {
        $now = date('Y-m-d H:i:s');
        return ['SELECT fi.product_id FROM flash_sale_products fi JOIN flash_sales fs ON fs.id = fi.flash_sale_id
                 WHERE fs.active = 1 AND fs.starts_at <= ? AND fs.ends_at > ?', [$now, $now]];
    }

    public static function hasLive(): bool
    {
        [$sql, $args] = self::liveProductSql();
        return (int) Database::query("SELECT COUNT(*) FROM ({$sql}) live", $args)->fetchColumn() > 0;
    }

    /**
     * Live sales for the storefront: each with its name, end time and card rows (customer-safe, priced).
     * @return array<int,array{id:int, name:string, ends_at:string, products: array}>
     */
    public static function live(int $limit = 2): array
    {
        $now = date('Y-m-d H:i:s');
        $sales = Database::query(
            'SELECT * FROM flash_sales WHERE active = 1 AND starts_at <= ? AND ends_at > ? ORDER BY ends_at ASC, id ASC',
            [$now, $now]
        )->fetchAll();
        $out = [];
        foreach ($sales as $s) {
            $products = Product::publicByIds(array_keys(self::itemsOf((int) $s['id'])));
            if ($products === []) { // nothing visible (drafts, deleted): does not use up one of the slots
                continue;
            }
            $out[] = ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'ends_at' => (string) $s['ends_at'], 'products' => $products];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * What the storefront block looks like for the form as it stands (nothing saved): live now, with the
     * products and percentages typed in. @return array{name:string, ends_at:string, products:array}|null
     */
    public static function preview(array $in): ?array
    {
        $items = self::items($in['items'] ?? []);
        $name = trim((string) ($in['name'] ?? ''));
        [, $end] = PricingService::window((string) ($in['starts_at'] ?? ''), (string) ($in['ends_at'] ?? ''));
        if ($items === [] || $end === null || strtotime($end) <= time()) {
            return null;
        }
        $offers = [];
        foreach ($items as $pid => $percent) {
            $offers[$pid] = ['percent' => $percent, 'ends_at' => $end, 'name' => $name];
        }
        $products = Product::publicByIds(array_keys($items), $offers);
        return ['name' => $name !== '' ? $name : 'Flash sale', 'ends_at' => $end, 'products' => $products];
    }
}
