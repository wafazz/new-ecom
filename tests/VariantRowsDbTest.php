<?php
// Variation rows (SKU, option, value, price, stock): validation, saving, updating, clearing. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Product;
use App\Services\VariantService as V;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
$pid = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Tee', 'slug' => 'tee', 'sku' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '30', 'status' => 'active', 'stock' => 0]);
$other = Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Cap', 'slug' => 'cap', 'sku' => 'CAP-1', 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '30', 'status' => 'active', 'stock' => 1]);
$prices = ['base_price' => '5.00', 'company_base_price' => '10.00', 'retail_price' => '30.00', 'discount_price' => null];
$row = fn(array $o = []) => $o + ['id' => 0, 'sku' => '', 'option' => 'Size', 'value' => 'S', 'price' => '', 'stock' => '3'];
$err = fn($r) => V::validateRows($r, $prices, $pid)['errors'];

check('valid rows pass', $err([$row(), $row(['value' => 'M', 'stock' => '0'])]) === []);
check('no rows -> form error', isset($err([])['form']) && isset($err('nonsense')['form']) && isset($err([[ 'sku' => '', 'option' => '', 'value' => '', 'price' => '', 'stock' => '' ]])['form']));
check('blank rows are skipped', count(V::validateRows([$row(), ['sku' => '', 'option' => '', 'value' => '', 'price' => '', 'stock' => '0']], $prices, $pid)['rows']) === 1);
check('option and value required', array_keys($err([$row(['option' => '', 'value' => ''])])) === ['variants[0][option]', 'variants[0][value]']);
check('duplicate option+value rejected (case-insensitive)', isset($err([$row(), $row(['option' => 'size', 'value' => 's'])])['variants[1][value]']));
check('stock must be a whole number >= 0', isset($err([$row(['stock' => '-1'])])['variants[0][stock]']) && isset($err([$row(['stock' => '1.5'])])['variants[0][stock]']) && isset($err([$row(['stock' => 'x'])])['variants[0][stock]']));
check('bad price format rejected', isset($err([$row(['price' => 'abc'])])['variants[0][price]']));
check('price below company base rejected', isset($err([$row(['price' => '9'])])['variants[0][price]']));
check('price at or above company base accepted', $err([$row(['price' => '10'])]) === [] && $err([$row(['price' => '45.5'])]) === []);
check('SKU repeated in the form rejected', isset($err([$row(['sku' => 'A']), $row(['value' => 'M', 'sku' => 'a'])])['variants[1][sku]']));
check('SKU used by another product rejected', isset($err([$row(['sku' => 'CAP-1'])])['variants[0][sku]']));
check('more than 3 different options rejected', isset($err([$row(['option' => 'A']), $row(['option' => 'B']), $row(['option' => 'C']), $row(['option' => 'D'])])['form']));
check('same option on many rows is one option', $err(array_map(fn($i) => $row(['value' => "V$i"]), range(1, 20))) === []);
check('over 100 rows rejected', isset($err(array_map(fn($i) => $row(['value' => "V$i"]), range(1, 101)))['form']));

$ok = V::validateRows([$row(['sku' => 'TEE-S', 'price' => '32']), $row(['value' => 'M', 'stock' => '7']), $row(['option' => 'Color', 'value' => 'Red', 'stock' => '2'])], $prices, $pid);
V::saveRows($pid, $ok['rows']);
$vs = V::variants($pid);
check('three variants saved with labels', array_column($vs, 'label') === ['S', 'M', 'Red']);
check('own SKU kept, blank SKU auto-generated', $vs[0]['sku'] === 'TEE-S' && str_starts_with((string) $vs[1]['sku'], "P{$pid}-V"));
check('each variant has its own stock and optional price', array_map(fn($v) => (int) $v['stock'], $vs) === [3, 7, 2] && $vs[0]['retail_price'] === '32.00' && $vs[1]['retail_price'] === null);
check('two options created with grouped values', array_column(V::options($pid), 'name') === ['Size', 'Color'] && count(V::options($pid)[0]['values']) === 2);
check('rows() round-trips for the form', V::rows($pid)[0] === ['id' => (int) $vs[0]['id'], 'sku' => 'TEE-S', 'option' => 'Size', 'value' => 'S', 'price' => '32.00', 'stock' => 3]);

// edit: change one, drop one, add one
$rows = V::rows($pid);
$edit = [
    ['id' => $rows[0]['id'], 'sku' => 'TEE-S', 'option' => 'Size', 'value' => 'Small', 'price' => '', 'stock' => '9'],
    ['id' => $rows[1]['id'], 'sku' => $rows[1]['sku'], 'option' => 'Size', 'value' => 'M', 'price' => '', 'stock' => '7'],
    ['id' => 0, 'sku' => 'TEE-XL', 'option' => 'Size', 'value' => 'XL', 'price' => '', 'stock' => '1'], // Red row omitted
];
$v2 = V::validateRows($edit, $prices, $pid);
check('editing own rows and keeping own SKU is valid', $v2['errors'] === []);
V::saveRows($pid, $v2['rows']);
$vs2 = V::variants($pid);
check('updated in place (same id), new row added, omitted row removed', array_column($vs2, 'label') === ['Small', 'M', 'XL'] && (int) $vs2[0]['id'] === (int) $vs[0]['id'] && $vs2[0]['retail_price'] === null);
check('editing a variation never changes its stock (that is Stock Control\'s job); a new row starts with the stock given, else 0', array_map(fn($v) => (int) $v['stock'], $vs2) === [3, 7, 1]);
check('removed variant is soft-deleted and frees its SKU', DB::query('SELECT COUNT(*) FROM product_variants WHERE id = ? AND deleted_at IS NOT NULL AND sku IS NULL', [$vs[2]['id']])->fetchColumn() == 1);
check('another product\'s variant id in the form is ignored (treated as new)', V::validateRows([$row(['id' => 999999])], $prices, $pid)['rows'][0]['id'] === 0);
check('option with no remaining rows disappears', array_column(V::options($pid), 'name') === ['Size']);
check('stored cost overrides on a variant survive an edit', (function () use ($pid, $prices, $vs2) {
    DB::query('UPDATE product_variants SET company_base_price = 12 WHERE id = ?', [$vs2[1]['id']]);
    $r = V::rows($pid);
    V::saveRows($pid, V::validateRows($r, $prices, $pid)['rows']);
    return DB::query('SELECT company_base_price FROM product_variants WHERE id = ?', [$vs2[1]['id']])->fetchColumn() === '12.00';
})());
check('price check uses the variant\'s own stored company price', isset(V::validateRows([[...V::rows($pid)[1], 'price' => '11']], $prices, $pid)['errors']['variants[0][price]']));

V::clear($pid);
check('clear() makes it a single product again', V::variants($pid) === [] && V::options($pid) === [] && DB::query('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND deleted_at IS NULL', [$pid])->fetchColumn() == 0);
