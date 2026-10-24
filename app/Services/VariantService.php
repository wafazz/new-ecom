<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class VariantService
{
    public const MAX_OPTIONS = 3;
    public const MAX_VARIANTS = 100;

    public static function options(int $productId): array
    {
        $opts = Database::query('SELECT id, name FROM product_options WHERE product_id = ? ORDER BY position, id', [$productId])->fetchAll();
        foreach ($opts as &$o) {
            $o['values'] = Database::query('SELECT id, value FROM product_option_values WHERE option_id = ? ORDER BY position, id', [$o['id']])->fetchAll();
        }
        return $opts;
    }

    public static function label(int $variantId): string
    {
        return implode(' / ', array_column(Database::query(
            'SELECT ov.value FROM variant_option_values vov
             JOIN product_option_values ov ON ov.id = vov.option_value_id
             JOIN product_options o ON o.id = ov.option_id
             WHERE vov.variant_id = ? ORDER BY o.position, o.id',
            [$variantId]
        )->fetchAll(), 'value'));
    }

    /** Variants safe to show customers: no cost / company prices. */
    public static function publicVariants(int $productId): array
    {
        return array_map(fn($v) => [
            'id' => (int) $v['id'],
            'label' => $v['label'],
            'option' => $v['option'],
            'sku' => $v['sku'],
            'stock' => (int) $v['stock'],
            'retail_price' => $v['retail_price'],
            'discount_price' => $v['discount_price'],
        ], self::variants($productId));
    }

    /** Active variants with a human label ("M / Red") and a stable combo key. */
    public static function variants(int $productId): array
    {
        $rows = Database::query('SELECT * FROM product_variants WHERE product_id = ? AND deleted_at IS NULL ORDER BY id', [$productId])->fetchAll();
        foreach ($rows as &$v) {
            $parts = Database::query(
                'SELECT o.name AS opt, ov.value FROM variant_option_values vov
                 JOIN product_option_values ov ON ov.id = vov.option_value_id
                 JOIN product_options o ON o.id = ov.option_id
                 WHERE vov.variant_id = ? ORDER BY o.position, o.id',
                [$v['id']]
            )->fetchAll();
            $v['label'] = implode(' / ', array_column($parts, 'value'));
            $v['option'] = implode(' / ', array_column($parts, 'opt'));
            $v['key'] = implode('|', array_map(fn($p) => $p['opt'] . '=' . $p['value'], $parts));
        }
        return $rows;
    }

    /** Rows for the product form: id, sku, option, value, price (blank = inherits), stock. */
    public static function rows(int $productId): array
    {
        $rows = [];
        foreach (Database::query('SELECT * FROM product_variants WHERE product_id = ? AND deleted_at IS NULL ORDER BY id', [$productId])->fetchAll() as $v) {
            $parts = Database::query(
                'SELECT o.name AS opt, ov.value FROM variant_option_values vov
                 JOIN product_option_values ov ON ov.id = vov.option_value_id
                 JOIN product_options o ON o.id = ov.option_id
                 WHERE vov.variant_id = ? ORDER BY o.position, o.id',
                [$v['id']]
            )->fetchAll();
            $rows[] = [
                'id' => (int) $v['id'],
                'sku' => (string) $v['sku'],
                'option' => implode(' / ', array_column($parts, 'opt')),
                'value' => implode(' / ', array_column($parts, 'value')),
                'price' => (string) ($v['retail_price'] ?? ''),
                'stock' => (int) $v['stock'],
            ];
        }
        return $rows;
    }

    /**
     * Validate submitted variation rows (sku, option, value, price, stock). Blank rows are skipped.
     *
     * @param mixed      $submitted  $_POST['variants'] (any shape; anything unexpected is ignored)
     * @param array|null $prices     the product's resolved base/company/retail/discount strings, or null to
     *                               skip the price-rule check (the product's own prices were invalid)
     * @return array{rows: array, errors: array<string,string>} errors keyed by input name, e.g. "variants[2][sku]", or "form"
     */
    public static function validateRows(mixed $submitted, ?array $prices, ?int $productId): array
    {
        $errors = [];
        $rows = [];
        $mine = [];
        if ($productId) {
            foreach (Database::query('SELECT * FROM product_variants WHERE product_id = ? AND deleted_at IS NULL', [$productId])->fetchAll() as $v) {
                $mine[(int) $v['id']] = $v;
            }
        }
        $str = fn(mixed $v): string => is_scalar($v) ? trim((string) $v) : '';
        $seenSku = [];
        $seenPair = [];
        $names = [];
        $usedIds = [];

        foreach (is_array($submitted) ? $submitted : [] as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $i = (string) $i;
            $f = fn(string $k) => "variants[{$i}][{$k}]";
            $sku = $str($r['sku'] ?? '');
            $opt = $str($r['option'] ?? '');
            $val = $str($r['value'] ?? '');
            $price = $str($r['price'] ?? '');
            $stockRaw = $str($r['stock'] ?? '');
            if ($sku === '' && $opt === '' && $val === '' && $price === '' && ($stockRaw === '' || $stockRaw === '0')) {
                continue;
            }
            $before = count($errors);

            if ($opt === '') {
                $errors[$f('option')] = 'Option is required (e.g. Size).';
            } elseif (mb_strlen($opt) > 50) {
                $errors[$f('option')] = 'Max 50 characters.';
            }
            if ($val === '') {
                $errors[$f('value')] = 'Value is required (e.g. Small).';
            } elseif (mb_strlen($val) > 50) {
                $errors[$f('value')] = 'Max 50 characters.';
            }
            if ($opt !== '' && $val !== '') {
                $pair = mb_strtolower($opt) . "\0" . mb_strtolower($val);
                if (isset($seenPair[$pair])) {
                    $errors[$f('value')] = 'Another row already has this option and value.';
                }
                $seenPair[$pair] = true;
            }

            $stock = $stockRaw === '' ? 0 : filter_var($stockRaw, FILTER_VALIDATE_INT);
            if ($stock === false || $stock < 0 || $stock > 1000000) {
                $errors[$f('stock')] = 'Stock must be a whole number, 0 or more.';
                $stock = 0;
            }

            $id = (int) ($r['id'] ?? 0);
            $id = isset($mine[$id]) && !isset($usedIds[$id]) ? $id : 0; // an id that is not this product's active variant is a new row
            if ($id) {
                $usedIds[$id] = true;
            }

            if ($sku !== '') {
                $key = mb_strtolower($sku);
                if (mb_strlen($sku) > 60) {
                    $errors[$f('sku')] = 'Max 60 characters.';
                } elseif (isset($seenSku[$key])) {
                    $errors[$f('sku')] = 'This SKU is repeated in another row.';
                } else {
                    $clash = (int) Database::query(
                        'SELECT (SELECT COUNT(*) FROM product_variants WHERE sku = ? AND id <> ?) + (SELECT COUNT(*) FROM products WHERE sku = ? AND id <> ?)',
                        [$sku, $id, $sku, $productId ?? 0]
                    )->fetchColumn();
                    if ($clash > 0) {
                        $errors[$f('sku')] = 'That SKU is already in use.';
                    }
                }
                $seenSku[$key] = true;
            }

            if ($price !== '') {
                if (!PricingService::isMoney($price)) {
                    $errors[$f('price')] = 'Price must be an amount like 12.50.';
                } elseif ($prices !== null) {
                    $kept = $id ? $mine[$id] : [];
                    $check = PricingService::validate(PricingService::resolveVariant($prices, [
                        'base_price' => $kept['base_price'] ?? null,
                        'company_base_price' => $kept['company_base_price'] ?? null,
                        'retail_price' => $price,
                        'discount_price' => $kept['discount_price'] ?? null,
                    ]));
                    if ($check['errors'] !== []) {
                        $errors[$f('price')] = reset($check['errors']);
                    }
                }
            }

            if (count($errors) === $before) {
                $names[mb_strtolower($opt)] = true;
            }
            $rows[] = [
                'id' => $id, 'sku' => $sku === '' ? null : $sku, 'option' => $opt, 'value' => $val,
                'price' => $price === '' || !PricingService::isMoney($price) ? null : PricingService::format(PricingService::cents($price)),
                'stock' => (int) $stock,
            ];
        }

        if ($rows === []) {
            $errors['form'] = 'Add at least one variation, or choose "Single product".';
        } elseif (count($rows) > self::MAX_VARIANTS) {
            $errors['form'] = 'A product can have at most ' . self::MAX_VARIANTS . ' variations.';
        } elseif (count(array_unique(array_map(fn($r) => mb_strtolower($r['option']), $rows))) > self::MAX_OPTIONS) {
            $errors['form'] = 'Use at most ' . self::MAX_OPTIONS . ' different options (e.g. Size, Color).';
        }
        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Make the product's variations exactly $rows (from validateRows): rows with an id are updated in
     * place (SKU and price only: stock and their other price overrides are kept), new rows are created
     * (with the row's stock, 0 when the form sends none), missing ones are soft-deleted.
     * Joins the caller's transaction when there is one.
     */
    public static function saveRows(int $productId, array $rows): void
    {
        $pdo = Database::connection();
        $own = !$pdo->inTransaction();
        $own && $pdo->beginTransaction();
        try {
            $keep = array_filter(array_column($rows, 'id'));
            foreach (Database::query('SELECT id FROM product_variants WHERE product_id = ? AND deleted_at IS NULL', [$productId])->fetchAll(\PDO::FETCH_COLUMN) as $vid) {
                if (!in_array((int) $vid, $keep, true)) { // freed first so a new row can reuse its SKU
                    Database::query('UPDATE product_variants SET deleted_at = NOW(), sku = NULL WHERE id = ?', [$vid]);
                }
            }
            Database::query('DELETE FROM product_options WHERE product_id = ?', [$productId]); // cascades values + links

            $optIds = [];
            $valIds = [];
            foreach ($rows as $r) {
                $ok = mb_strtolower($r['option']);
                if (!isset($optIds[$ok])) {
                    Database::query('INSERT INTO product_options (product_id, name, position) VALUES (?, ?, ?)', [$productId, $r['option'], count($optIds)]);
                    $optIds[$ok] = (int) $pdo->lastInsertId();
                }
                $vk = $ok . "\0" . mb_strtolower($r['value']);
                if (!isset($valIds[$vk])) {
                    Database::query('INSERT INTO product_option_values (option_id, value, position) VALUES (?, ?, ?)', [$optIds[$ok], $r['value'], count($valIds)]);
                    $valIds[$vk] = (int) $pdo->lastInsertId();
                }

                if ($r['id']) {
                    $vid = (int) $r['id'];
                    Database::query('UPDATE product_variants SET sku = ?, retail_price = ? WHERE id = ?', [$r['sku'], $r['price'], $vid]); // stock only changes on the Stock Control page
                } else {
                    Database::query('INSERT INTO product_variants (product_id, sku, retail_price, stock) VALUES (?, ?, ?, ?)', [$productId, $r['sku'], $r['price'], $r['stock']]);
                    $vid = (int) $pdo->lastInsertId();
                }
                if ($r['sku'] === null) {
                    Database::query('UPDATE product_variants SET sku = ? WHERE id = ?', [sprintf('P%d-V%d', $productId, $vid), $vid]);
                }
                Database::query('INSERT INTO variant_option_values (variant_id, option_value_id) VALUES (?, ?)', [$vid, $valIds[$vk]]);
            }
            $own && $pdo->commit();
        } catch (\Throwable $e) {
            $own && $pdo->rollBack();
            throw $e;
        }
    }

    /** Back to a single product: no options, every variation soft-deleted. */
    public static function clear(int $productId): void
    {
        self::saveRows($productId, []);
    }
}
