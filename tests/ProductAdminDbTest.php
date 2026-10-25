<?php
// Products list filters/sorts/counts and the quick-edit + bulk service. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Product;
use App\Services\ProductAdminService as S;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query('DELETE FROM categories');
DB::query("INSERT INTO categories (name, slug) VALUES ('Bags', 'bags'), ('Candles', 'candles')");
$bags = (int) DB::query("SELECT id FROM categories WHERE slug = 'bags'")->fetchColumn();
$mk = fn(string $name, string $sku, string $retail, int $stock, string $status = 'active', ?int $cat = null) => Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'sku' => $sku, 'category_id' => $cat, 'base_price' => '1', 'company_base_price' => '2', 'retail_price' => $retail, 'stock' => $stock, 'status' => $status]);
$tote = $mk('Linen Tote', 'TOTE-1', '45', 12, 'active', $bags);
$scarf = $mk('Silk Scarf', 'SCARF-1', '89', 0, 'active', $bags);
$cand = $mk('Cedar Candle', 'CAN-1', '35', 3, 'draft');
$mug = $mk('Glazed Mug 100% cotton_', 'MUG-1', '20', 40, 'active');
$shirt = $mk('Tee', 'TEE-1', '30', 0, 'active');                 // simple stock 0 ...
DB::query("INSERT INTO product_variants (product_id, sku, stock) VALUES (?, 'TEE-S', 4), (?, 'TEE-M', 4)", [$shirt, $shirt]); // ... but variants total 8
$names = fn(array $r) => array_column($r['rows'], 'name');

check('no filter lists every live product', Product::paginate('', null, '', 1, 50)['total'] === 5);

// brands
DB::query("INSERT INTO brands (name, slug) VALUES ('Acme', 'acme'), ('Zed', 'zed')");
$acme = (int) DB::query("SELECT id FROM brands WHERE slug = 'acme'")->fetchColumn();
Product::update($tote, ['brand_id' => $acme]);
Product::update($mug, ['brand_id' => $acme]);
check('brand filter (admin)', $names(Product::paginate('', null, '', 1, 50, '', 'name', $acme)) === ['Glazed Mug 100% cotton_', 'Linen Tote']);
check('brand + category filters combine', $names(Product::paginate('', $bags, '', 1, 50, '', 'newest', $acme)) === ['Linen Tote']);
check('brand filter (shop, by slug) and brand name on rows', array_column(Product::publicList('', null, 'name', 1, 50, 'acme')['rows'], 'brand_name') === ['Acme', 'Acme'] && Product::publicList('', null, 'newest', 1, 50, 'zed')['total'] === 0);
check('public brands list only brands with active products', array_column(Product::publicBrands(), 'slug') === ['acme']);
DB::query('DELETE FROM brands WHERE id = ?', [$acme]);
check('deleting a brand keeps its products, brand cleared', Product::find($tote) !== null && Product::find($tote)['brand_id'] === null);

// parent categories
DB::query("INSERT INTO categories (name, slug) VALUES ('Home', 'home')");
$home = (int) DB::query("SELECT id FROM categories WHERE slug = 'home'")->fetchColumn();
DB::query('UPDATE categories SET parent_id = ? WHERE id = (SELECT id FROM (SELECT id FROM categories WHERE slug = ?) x)', [$home, 'candles']);
$candlesId = (int) DB::query("SELECT id FROM categories WHERE slug = 'candles'")->fetchColumn();
Product::update($scarf, ['category_id' => $candlesId]);
check('parent category filter includes sub-category products', $names(Product::paginate('', $home, '', 1, 50, '', 'name')) === ['Silk Scarf']);
check('child filter does not include the parent', Product::paginate('', $candlesId, '', 1, 50)['total'] === 1 && App\Models\Category::withChildIds($home) === [$home, $candlesId]);
check('category tree: parent first, child labelled', array_column(App\Models\Category::options(), 'label') === ['Bags', 'Home', 'Home › Candles']);
check('shop: parent slug shows child products', array_column(Product::publicList('', 'home', 'name', 1, 50)['rows'], 'name') === ['Silk Scarf']);
Product::update($scarf, ['category_id' => $bags]);
DB::query('DELETE FROM categories WHERE id = ?', [$home]);
check('deleting a parent makes its children top-level', DB::query('SELECT parent_id FROM categories WHERE id = ?', [$candlesId])->fetchColumn() === null);
check('search matches name and SKU, case-insensitive', $names(Product::paginate('tote', null, '', 1, 50)) === ['Linen Tote'] && $names(Product::paginate('scarf-1', null, '', 1, 50)) === ['Silk Scarf']);
check('LIKE wildcards in the search are literal (% and _)', $names(Product::paginate('100%', null, '', 1, 50)) === ['Glazed Mug 100% cotton_'] && Product::paginate('%', null, '', 1, 50)['total'] === 1 && Product::paginate('_', null, '', 1, 50)['total'] === 1);
check('category filter', $names(Product::paginate('', $bags, '', 1, 50)) === ['Silk Scarf', 'Linen Tote']);
check('status filter', Product::paginate('', null, 'draft', 1, 50)['total'] === 1 && Product::paginate('', null, 'active', 1, 50)['total'] === 4 && Product::paginate('', null, 'bogus', 1, 50)['total'] === 5);
check('stock=out: only products with nothing sellable (variants count)', $names(Product::paginate('', null, '', 1, 50, 'out')) === ['Silk Scarf']);
check('stock=low: 1..5 left', $names(Product::paginate('', null, '', 1, 50, 'low')) === ['Cedar Candle']);
check('filters combine', Product::paginate('', $bags, 'active', 1, 50, 'out')['total'] === 1 && Product::paginate('', $bags, 'draft', 1, 50, 'out')['total'] === 0);
check('sort: name / price / stock', $names(Product::paginate('', null, '', 1, 50, '', 'name'))[0] === 'Cedar Candle' && $names(Product::paginate('', null, '', 1, 50, '', 'price_desc'))[0] === 'Silk Scarf' && $names(Product::paginate('', null, '', 1, 50, '', 'price_asc'))[0] === 'Glazed Mug 100% cotton_' && $names(Product::paginate('', null, '', 1, 50, '', 'stock_asc'))[0] === 'Silk Scarf');
check('unknown sort value is ignored (newest first)', $names(Product::paginate('', null, '', 1, 50, '', 'name; DROP TABLE products'))[0] === 'Tee');
check('pagination slices the sorted set', count(Product::paginate('', null, '', 2, 2, '', 'name')['rows']) === 2 && Product::paginate('', null, '', 3, 2, '', 'name')['rows'][0]['name'] === 'Tee');
$c = Product::counts();
check('counts: total/active/draft/out/low', $c === ['total' => 5, 'active' => 4, 'draft' => 1, 'out_of_stock' => 1, 'low' => 1]);
check('deleted products drop out of lists and counts', (function () use ($mug) { Product::softDelete($mug); return Product::paginate('', null, '', 1, 50)['total'] === 4 && Product::counts()['total'] === 4; })());

// quick edits
check('setStatus: toggles and validates', S::setStatus($cand, 'active')['ok'] === true && Product::find($cand)['status'] === 'active' && S::setStatus($cand, 'archived')['ok'] === false && Product::find($cand)['status'] === 'active' && S::setStatus($mug, 'draft')['ok'] === false);

// bulk
check('bulk: unknown action / nothing selected / junk ids', S::bulk('explode', [$tote])['ok'] === false && S::bulk('draft', [])['ok'] === false && S::bulk('draft', 'x')['ok'] === false && S::bulk('draft', [0, -3, 'abc', ['x']])['ok'] === false);
check('bulk: cap of ' . S::MAX_BULK . ' ids', S::bulk('draft', range(1, S::MAX_BULK + 1))['ok'] === false);
$r = S::bulk('draft', [$tote, $scarf, $scarf, 999999, $mug]);
check('bulk draft: dedupes, ignores unknown and deleted ids, counts what changed', $r === ['ok' => true, 'count' => 2] && Product::find($tote)['status'] === 'draft' && Product::find($scarf)['status'] === 'draft' && Product::find($cand)['status'] === 'active');
check('bulk activate', S::bulk('activate', [$tote])['ok'] === true && Product::find($tote)['status'] === 'active');
$r = S::bulk('delete', [$scarf, $cand]);
check('bulk delete: soft-deletes, frees the SKU, keeps untouched products', $r['count'] === 2 && Product::findActive($scarf) === null && Product::find($scarf)['deleted_at'] !== null && Product::find($scarf)['sku'] === null && Product::findActive($tote) !== null);
check('bulk: all ids gone -> clear error, nothing happens', S::bulk('delete', [$scarf, $cand])['ok'] === false);
