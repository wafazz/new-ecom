<?php
// Product image list: ordering, primary, removal, limits (saved images only; uploads are covered by the HTTP checks). Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Product;
use App\Services\ProductImageService as I;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query('DELETE FROM product_images');
$pid = Product::insert(['name' => 'Vase', 'slug' => 'vase', 'sku' => 'V1', 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '30', 'status' => 'active', 'stock' => 1]);
$other = Product::insert(['name' => 'Jar', 'slug' => 'jar', 'sku' => 'J1', 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '30', 'status' => 'active', 'stock' => 1]);
$mk = function (int $p, string $name, int $primary = 0) {
    DB::query('INSERT INTO product_images (product_id, path, is_primary) VALUES (?, ?, ?)', [$p, "uploads/products/{$name}.jpg", $primary]);
    return (int) DB::connection()->lastInsertId();
};
[$a, $b, $c] = [$mk($pid, 'a', 1), $mk($pid, 'b'), $mk($pid, 'c')];
$foreign = $mk($other, 'z', 1);
$paths = fn() => array_column(Product::images($pid), 'path');

check('no order and no files -> nothing changes', I::plan($pid, null, null) === ['plan' => null, 'errors' => []]);
$r = I::plan($pid, ["e$c", "e$a", "e$b"], null);
check('order plan is accepted', $r['errors'] === [] && $r['plan']['tokens'] === ["e$c", "e$a", "e$b"]);
I::apply($pid, $r['plan']);
check('reordering sets positions and the first becomes primary', $paths() === ['uploads/products/c.jpg', 'uploads/products/a.jpg', 'uploads/products/b.jpg'] && (int) Product::images($pid)[0]['is_primary'] === 1 && DB::query('SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_primary = 1', [$pid])->fetchColumn() == 1);

$r = I::plan($pid, ["e$c", "e$b"], null);
$res = I::apply($pid, $r['plan']);
check('an image left out of the order is deleted and reported for unlinking', $paths() === ['uploads/products/c.jpg', 'uploads/products/b.jpg'] && $res['unlink'] === ['uploads/products/a.jpg']);

$r = I::plan($pid, ["e$c", "e$foreign", "e999999", 'x1', "e$c", ['nested'], 'n0'], null);
check('foreign, unknown, malformed, duplicate and unmatched tokens are ignored', $r['plan']['tokens'] === ["e$c"] && $r['errors'] === []);
check('the other product\'s image is untouched by this save', (function () use ($pid, $r, $foreign) { I::apply($pid, $r['plan']); return DB::query('SELECT COUNT(*) FROM product_images WHERE id = ?', [$foreign])->fetchColumn() == 1; })());

$r = I::plan($pid, [''], null);
I::apply($pid, $r['plan']);
check('an empty order removes every image', Product::images($pid) === []);

for ($i = 0; $i < 6; $i++) { $mk($pid, "m$i", $i === 0 ? 1 : 0); }
$ids = array_column(Product::images($pid), 'id');
check('more than 5 images is an error that says how many to remove', isset(I::plan($pid, array_map(fn($i) => "e$i", $ids), null)['errors']['images']) && str_contains(I::plan($pid, array_map(fn($i) => "e$i", $ids), null)['errors']['images'], 'Remove 1'));
check('exactly 5 is fine', I::plan($pid, array_map(fn($i) => "e$i", array_slice($ids, 0, 5)), null)['errors'] === []);
check('legacy product over the limit can still be saved without touching images', I::plan($pid, null, null)['plan'] === null);
check('MAX is 5', I::MAX === 5);
