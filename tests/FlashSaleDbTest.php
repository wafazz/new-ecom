<?php
// Flash sales in the database: timeframes and their status, which price the storefront and checkout use, overlapping
// sales, switching off, deleting, and that the admin preview saves nothing. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\FlashSaleService as F;
use App\Services\OrderService;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();

$mk = fn(string $name, string $retail, string $status = 'active') => Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
    'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'sku' => strtoupper(substr(str_replace(' ', '', $name), 0, 6)), 'category_id' => null, 'brand_id' => null,
    'base_price' => '5', 'company_base_price' => '10', 'retail_price' => $retail, 'discount_price' => null, 'stock' => 20, 'status' => $status,
]);
$tote = $mk('Linen Tote', '50.00');
$mug = $mk('Glazed Mug', '20.00');
$draft = $mk('Hidden Draft', '10.00', 'draft');

$now = time();
$at = fn(int $offset) => date('Y-m-d H:i:s', $now + $offset);
$HOUR = 3600;

// One sale running now, one scheduled for tomorrow, one that ended yesterday.
$running = F::save(null, ['name' => 'Now', 'starts_at' => $at(-$HOUR), 'ends_at' => $at($HOUR)], [$tote => '20.00', $mug => '10.00']);
$later = F::save(null, ['name' => 'Tomorrow', 'starts_at' => $at(86400), 'ends_at' => $at(86400 + $HOUR)], [$tote => '50.00']);
$past = F::save(null, ['name' => 'Yesterday', 'starts_at' => $at(-86400 - $HOUR), 'ends_at' => $at(-86400)], [$mug => '40.00']);

$count = fn(string $sql, array $args = []) => (int) DB::query($sql, $args)->fetchColumn();
check('a sale saves with its product rows', $count('SELECT COUNT(*) FROM flash_sale_products WHERE flash_sale_id = ?', [$running]) === 2);
check('percentages are stored to two decimals', (string) DB::query('SELECT percent_off FROM flash_sale_products WHERE flash_sale_id = ? AND product_id = ?', [$running, $tote])->fetchColumn() === '20.00');

$status = [];
foreach (F::listing() as $row) {
    $status[$row['name']] = $row['status'];
}
check('timeframe status: running is live', ($status['Now'] ?? '') === 'live');
check('timeframe status: tomorrow is scheduled', ($status['Tomorrow'] ?? '') === 'scheduled');
check('timeframe status: yesterday is ended', ($status['Yesterday'] ?? '') === 'ended');

check('storefront prices the running sale', (float) Product::publicByIds([$tote])[0]['price'] === 40.0);
check('a scheduled sale does not price anything yet (tote still at the running 20%)', F::offersFor([$tote])[$tote]['name'] === 'Now' && F::offersFor([$tote])[$tote]['percent'] === '20.00');
check('an ended sale does not price anything (mug still at the running 10%)', F::offersFor([$mug])[$mug]['name'] === 'Now' && F::offersFor([$mug])[$mug]['percent'] === '10.00');
check('the live sale list includes the running sale only', array_map(fn($s) => $s['name'], F::live()) === ['Now']);
check('the on-sale flag is set on a flash product', Product::publicByIds([$tote])[0]['on_sale'] === true);

// Overlap: a second running sale with a bigger percentage wins for the product it shares.
$bigger = F::save(null, ['name' => 'Bigger', 'starts_at' => $at(-$HOUR), 'ends_at' => $at($HOUR / 2)], [$tote => '30.00']);
$best = Product::publicByIds([$tote])[0];
check('overlapping sales: the bigger percentage applies', $best['flash_name'] === 'Bigger' && (float) $best['price'] === 35.0);
F::setActive($bigger, false);
check('switching a sale off stops its prices', Product::publicByIds([$tote])[0]['flash_name'] === 'Now');
F::setActive($bigger, true);

// A product that is still a draft is never shown, so a sale holding only drafts is not shown either.
F::save(null, ['name' => 'Drafts only', 'starts_at' => $at(-$HOUR), 'ends_at' => $at($HOUR)], [$draft => '10.00']);
check('a sale with only draft products is not on the storefront', !in_array('Drafts only', array_map(fn($s) => $s['name'], F::live()), true));
// Two empty sales that end sooner must not use up the storefront's slots and push the real sale off.
F::save(null, ['name' => 'Empty A', 'starts_at' => $at(-$HOUR), 'ends_at' => $at(600)], [$draft => '10.00']);
F::save(null, ['name' => 'Empty B', 'starts_at' => $at(-$HOUR), 'ends_at' => $at(700)], [$draft => '10.00']);
check('empty sales do not hide a real running sale', in_array('Now', array_map(fn($s) => $s['name'], F::live()), true));

// Checkout charges the flash price, and the order keeps it.
DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', random_bytes(8))]);
$cartId = (int) DB::connection()->lastInsertId();
DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty) VALUES (?,?,?,?)', [$cartId, $mug, null, 2]);
$contact = ['name' => 'Flash Buyer', 'email' => 'flash@example.test', 'phone' => '0123456789', 'line1' => '1 Jalan Mawar', 'line2' => '', 'city' => 'Shah Alam', 'state' => 'Selangor', 'postcode' => '40150', 'notes' => ''];
$o = CheckoutService::place($cartId, $contact, 'cod', null);
$line = DB::query('SELECT unit_price, retail_price FROM order_items WHERE order_id = ?', [(int) $o['order_id']])->fetch();
check('checkout charges the flash price for the mug (10% off 20.00)', (float) $line['unit_price'] === 18.0);
check('the order keeps the normal retail price for reference', (float) $line['retail_price'] === 20.0);
check('the order total uses the flash price', (float) OrderService::find((int) $o['order_id'])['subtotal'] === 36.0);

// The preview prices a draft form without saving it.
$sales = $count('SELECT COUNT(*) FROM flash_sales');
$local = fn(int $offset) => date('Y-m-d\TH:i', $now + $offset); // the form's datetime-local format
$preview = F::preview(['name' => 'Preview only', 'starts_at' => $local(-$HOUR), 'ends_at' => $local($HOUR), 'items' => [$mug => ['on' => '1', 'percent' => '50']]]);
check('preview shows the typed percentage on the product', $preview !== null && (float) $preview['products'][0]['price'] === 10.0);
check('preview saves nothing', $count('SELECT COUNT(*) FROM flash_sales') === $sales);

// Deleting a sale removes its product rows and puts the prices back.
F::delete($later);
check('deleting a sale removes its product rows', $count('SELECT COUNT(*) FROM flash_sale_products WHERE flash_sale_id = ?', [$later]) === 0);
F::delete($running);
check('deleting the running sale restores the normal price', (float) Product::publicByIds([$mug])[0]['price'] === 20.0);
