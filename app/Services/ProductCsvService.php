<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Import and export of regular prices and stock, matched by SKU. Each row can be a product SKU or a variant SKU.
 * Blank cells mean "leave as it is". A bad row is reported and skipped; the other rows still apply.
 * Creating products is not done by import: it needs images, categories and variants, which the product editor handles.
 */
final class ProductCsvService
{
    public const MAX_ROWS = 2000;
    public const MAX_BYTES = 524288; // 512 KB
    public const HEADERS = ['sku', 'retail_price', 'stock'];

    /** Every product and option that has a SKU, with its current price and stock. @return array<int,array<int,string>> */
    public static function exportRows(): array
    {
        $rows = [['sku', 'name', 'option', 'retail_price', 'stock']];
        foreach (Database::query("SELECT p.sku, p.name, '' AS opt, p.retail_price, p.stock FROM products p WHERE p.deleted_at IS NULL AND p.sku IS NOT NULL AND p.sku <> '' ORDER BY p.name, p.id")->fetchAll() as $r) {
            $rows[] = [(string) $r['sku'], (string) $r['name'], '', (string) $r['retail_price'], (string) $r['stock']];
        }
        foreach (Database::query("SELECT v.sku, p.name, v.id AS vid, COALESCE(v.retail_price, p.retail_price) AS price, v.stock FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.deleted_at IS NULL AND p.deleted_at IS NULL AND v.sku IS NOT NULL AND v.sku <> '' ORDER BY p.name, v.id")->fetchAll() as $r) {
            $rows[] = [(string) $r['sku'], (string) $r['name'], VariantService::label((int) $r['vid']), (string) $r['price'], (string) $r['stock']];
        }
        return $rows;
    }

    /**
     * Checks every row without changing anything.
     * @return array{fatal:?string, rows:array<int,array{line:int, sku:string, ok:bool, error:?string, target:?array, price:?string, stock:?int}>, ok:int, bad:int}
     */
    public static function check(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv; // strip a UTF-8 byte-order mark from spreadsheet exports
        $res = ['fatal' => null, 'rows' => [], 'ok' => 0, 'bad' => 0];
        if (strlen($csv) > self::MAX_BYTES) {
            $res['fatal'] = 'That file is too big. Keep it under 512 KB.';
            return $res;
        }
        $h = fopen('php://memory', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $header = fgetcsv($h);
        if (!is_array($header)) {
            $res['fatal'] = 'The file is empty.';
            return $res;
        }
        $header = array_map(fn($c) => strtolower(trim((string) $c)), $header);
        if (!in_array('sku', $header, true) || !in_array('retail_price', $header, true) && !in_array('stock', $header, true)) {
            $res['fatal'] = 'The first row must be headers: sku, and retail_price or stock (or both).';
            return $res;
        }
        $col = fn(string $name): ?int => ($i = array_search($name, $header, true)) === false ? null : $i;
        $iSku = $col('sku');
        $iPrice = $col('retail_price');
        $iStock = $col('stock');

        $seen = [];
        $line = 1;
        while (($cells = fgetcsv($h)) !== false) {
            $line++;
            if ($cells === [null]) {
                continue; // blank line
            }
            if (count($res['rows']) >= self::MAX_ROWS) {
                $res['fatal'] = 'More than ' . self::MAX_ROWS . ' rows. Split the file and import it in parts.';
                break;
            }
            $sku = trim((string) ($cells[$iSku] ?? ''));
            $price = $iPrice === null ? '' : trim((string) ($cells[$iPrice] ?? ''));
            $stock = $iStock === null ? '' : trim((string) ($cells[$iStock] ?? ''));
            $row = ['line' => $line, 'sku' => $sku, 'ok' => false, 'error' => null, 'target' => null, 'price' => null, 'stock' => null];

            if ($sku === '') {
                $row['error'] = 'The SKU is empty.';
            } elseif (isset($seen[strtolower($sku)])) {
                $row['error'] = 'This SKU is already on an earlier line.';
            } elseif (($target = self::find($sku)) === null) {
                $row['error'] = 'No product or option has this SKU.';
            } elseif ($price === '' && $stock === '') {
                $row['error'] = 'Nothing to change: fill in a price or a stock count.';
            } elseif ($price !== '' && !self::validPrice($price)) {
                $row['error'] = 'Price must be a number from 0.01 to 99999.99 with at most 2 decimals.';
            } elseif ($stock !== '' && (!preg_match('/^\d{1,7}$/', $stock) || (int) $stock > StockService::MAX)) {
                $row['error'] = 'Stock must be a whole number, 0 or more.';
            } else {
                $row['ok'] = true;
                $row['target'] = $target;
                $row['price'] = $price === '' ? null : number_format((float) $price, 2, '.', '');
                $row['stock'] = $stock === '' ? null : (int) $stock;
                $seen[strtolower($sku)] = true;
            }
            $res['rows'][] = $row;
            $row['ok'] ? $res['ok']++ : $res['bad']++;
        }
        fclose($h);
        return $res;
    }

    /** Applies the rows that passed check(). One transaction, audit-logged. @return array{prices:int, stock:int} */
    public static function apply(string $csv, int $actorId): array
    {
        $checked = self::check($csv);
        if ($checked['fatal'] !== null) {
            throw new \DomainException($checked['fatal']);
        }
        $good = array_values(array_filter($checked['rows'], fn($r) => $r['ok']));
        if ($good === []) {
            throw new \DomainException('No row can be imported. Fix the errors and try again.');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $prices = 0;
            $stock = 0;
            foreach ($good as $r) {
                $t = $r['target'];
                if ($r['price'] !== null && $r['price'] !== number_format((float) $t['old_price'], 2, '.', '')) {
                    Database::query($t['variant_id'] !== null ? 'UPDATE product_variants SET retail_price = ? WHERE id = ?' : 'UPDATE products SET retail_price = ? WHERE id = ?',
                        [$r['price'], $t['variant_id'] ?? $t['product_id']]);
                    $prices++;
                }
                if ($r['stock'] !== null && $r['stock'] !== (int) $t['old_stock']) {
                    StockService::adjust((int) $t['product_id'], $t['variant_id'], 'set', (string) $r['stock'], 'correction', 'CSV import', $actorId);
                    $stock++;
                }
            }
            AuditService::log($actorId, 'catalogue.import', "{$prices} price(s), {$stock} stock line(s) from CSV");
            $pdo->commit();
            return ['prices' => $prices, 'stock' => $stock];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function validPrice(string $v): bool
    {
        if (preg_match('/^\d{1,5}(\.\d{1,2})?$/', $v) !== 1) {
            return false;
        }
        $f = (float) $v;
        return $f >= 0.01 && $f <= 99999.99;
    }

    /** A variant or product with this SKU, with its current price and stock. Variant SKUs win. */
    private static function find(string $sku): ?array
    {
        $v = Database::query(
            'SELECT v.id vid, v.product_id, COALESCE(v.retail_price, p.retail_price) old_price, v.stock old_stock FROM product_variants v JOIN products p ON p.id = v.product_id
             WHERE v.sku = ? AND v.deleted_at IS NULL AND p.deleted_at IS NULL', [$sku]
        )->fetch();
        if ($v) {
            return ['product_id' => (int) $v['product_id'], 'variant_id' => (int) $v['vid'], 'old_price' => (string) $v['old_price'], 'old_stock' => (int) $v['old_stock']];
        }
        $p = Database::query('SELECT id, retail_price, stock FROM products WHERE sku = ? AND deleted_at IS NULL', [$sku])->fetch();
        if ($p) {
            return ['product_id' => (int) $p['id'], 'variant_id' => null, 'old_price' => (string) $p['retail_price'], 'old_stock' => (int) $p['stock']];
        }
        return null;
    }
}
