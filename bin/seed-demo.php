<?php
declare(strict_types=1);

/**
 * Demo data: example categories, brands, products (single and with variations, with placeholder images)
 * and sample orders that are all NEW (status "pending").
 *
 *   php bin/seed-demo.php                 add the demo data (safe to re-run: nothing is duplicated)
 *   php bin/seed-demo.php --orders=20     how many sample orders to have in total (default 12, max 100)
 *   php bin/seed-demo.php --no-images     skip the generated placeholder images
 *   php bin/seed-demo.php --remove        delete everything this script created (and only that)
 *   php bin/seed-demo.php --force         allow running when APP_ENV=production (don't)
 *
 * Everything it creates is recognisable: categories, brands and products have slugs starting with "demo-";
 * sample orders use customer emails ending in @demo.example.test. Orders are placed through the real
 * CheckoutService, so prices, cost snapshots, stock deduction and order history are exactly what checkout makes.
 */
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Database as DB;
use App\Core\Str;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\RichText;
use App\Services\StockService;
use App\Services\UploadService;
use App\Services\VariantService;

const DEMO_DOMAIN = 'demo.example.test';

$opts = ['orders' => 12, 'images' => true, 'remove' => false, 'force' => false];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--remove') {
        $opts['remove'] = true;
    } elseif ($a === '--no-images') {
        $opts['images'] = false;
    } elseif ($a === '--force') {
        $opts['force'] = true;
    } elseif (preg_match('/^--orders=(\d{1,3})$/', $a, $m)) {
        $opts['orders'] = min(100, (int) $m[1]);
    } else {
        fwrite(STDERR, "Unknown option: {$a}\nSee the top of bin/seed-demo.php for usage.\n");
        exit(1);
    }
}
if ((string) env('APP_ENV', 'production') === 'production' && !$opts['force']) {
    fwrite(STDERR, "Refusing to add demo data when APP_ENV=production (use --force if you really mean it).\n");
    exit(1);
}
try {
    DB::query('SELECT 1 FROM product_images LIMIT 1');
    DB::query('SELECT position FROM product_images LIMIT 1');
    DB::query('SELECT parent_id FROM categories LIMIT 1');
    DB::query('SELECT brand_id FROM products LIMIT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "The database is not up to date. Run: php bin/migrate.php\n");
    exit(1);
}

// ---- remove ------------------------------------------------------------------------------------------------
if ($opts['remove']) {
    $pdo = DB::connection();
    $orders = DB::query('SELECT id FROM orders WHERE customer_email LIKE ?', ['%@' . DEMO_DOMAIN])->fetchAll(PDO::FETCH_COLUMN);
    $images = DB::query("SELECT i.path FROM product_images i JOIN products p ON p.id = i.product_id WHERE p.slug LIKE 'demo-%'")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->beginTransaction();
    DB::query('DELETE FROM orders WHERE customer_email LIKE ?', ['%@' . DEMO_DOMAIN]);   // items + history cascade
    $p = DB::query("DELETE FROM products WHERE slug LIKE 'demo-%'")->rowCount();           // variants, images, options cascade
    $c = DB::query("DELETE FROM categories WHERE slug LIKE 'demo-%' AND parent_id IS NOT NULL")->rowCount();
    $c += DB::query("DELETE FROM categories WHERE slug LIKE 'demo-%'")->rowCount();
    $b = DB::query("DELETE FROM brands WHERE slug LIKE 'demo-%'")->rowCount();
    $pdo->commit();
    foreach ($images as $path) {
        UploadService::delete($path);
    }
    echo "Removed: " . count($orders) . " orders, {$p} products, " . count($images) . " images, {$c} categories, {$b} brands.\n";
    exit(0);
}

// ---- helpers -----------------------------------------------------------------------------------------------
function demo_category(string $name, ?int $parent): int
{
    $slug = 'demo-' . Str::slug($name);
    $id = DB::query('SELECT id FROM categories WHERE slug = ?', [$slug])->fetchColumn();
    return $id ? (int) $id : Category::insert(['name' => $name, 'slug' => $slug, 'parent_id' => $parent]);
}

function demo_brand(string $name): int
{
    $slug = 'demo-' . Str::slug($name);
    $id = DB::query('SELECT id FROM brands WHERE slug = ?', [$slug])->fetchColumn();
    return $id ? (int) $id : Brand::insert(['name' => $name, 'slug' => $slug]);
}

/** A simple generated placeholder (gradient, circle, product name) saved like an upload. Null without GD. */
function demo_image(string $label, array $rgb, int $shade): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $w = 800;
    $im = imagecreatetruecolor($w, $w);
    [$r, $g, $b] = $rgb;
    for ($y = 0; $y < $w; $y++) {
        $k = 0.55 + 0.45 * ($y / $w) - 0.08 * $shade;
        imageline($im, 0, $y, $w, $y, imagecolorallocate($im, (int) min(255, $r * $k + 40), (int) min(255, $g * $k + 40), (int) min(255, $b * $k + 40)));
    }
    imagefilledellipse($im, (int) ($w / 2), (int) ($w / 2 - 20), 360 + $shade * 40, 360 + $shade * 40, imagecolorallocatealpha($im, 255, 255, 255, 85));
    $text = mb_substr(preg_replace('/[^\x20-\x7e]/', '', $label) ?: 'Demo', 0, 28);
    $tw = imagefontwidth(5) * strlen($text);
    $strip = imagecreatetruecolor($tw, 18);                       // draw small, then enlarge: GD's built-in fonts are tiny
    imagefill($strip, 0, 0, imagecolorallocate($strip, 255, 0, 255));
    imagecolortransparent($strip, imagecolorallocate($strip, 255, 0, 255));
    imagestring($strip, 5, 0, 1, $text, imagecolorallocate($strip, 255, 255, 255));
    $scale = min(3.0, 700 / $tw);
    imagecopyresized($im, $strip, (int) (($w - $tw * $scale) / 2), (int) ($w / 2 + 230), 0, 0, (int) ($tw * $scale), (int) (18 * $scale), $tw, 18);
    $dir = BASE_PATH . '/public/uploads/products';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }
    $name = bin2hex(random_bytes(16)) . '.png';
    $ok = imagepng($im, $dir . '/' . $name, 6);
    return $ok ? 'uploads/products/' . $name : null;
}

// ---- catalogue ---------------------------------------------------------------------------------------------
mt_srand(20261024); // same shape on every run
$home = demo_category('Home & Living', null);
$candles = demo_category('Candles & Scents', $home);
$decor = demo_category('Decor', $home);
$fashion = demo_category('Fashion', null);
$tees = demo_category('T-Shirts & Hoodies', $fashion);
$bags = demo_category('Bags', $fashion);
$lumen = demo_brand('Lumen Home');
$north = demo_brand('Northwind Apparel');
$kopi = demo_brand('Kopi & Clay');

$cat = [
    // name, sku, category, brand, [base, company, retail, discount|null], stock, status, colour, description, variations (null = single)
    ['Cedar Soy Candle', 'DEMO-CAN-01', $candles, $lumen, [14, 22, 35, 29], 40, 'active', [196, 140, 90],
        '<p>Hand-poured soy wax candle with a warm <strong>cedarwood</strong> scent. Burns clean for about 45 hours.</p><ul><li>200 g soy wax</li><li>Cotton wick</li><li>Reusable glass jar</li></ul>', null],
    ['Ceramic Pour-Over Mug', 'DEMO-MUG-01', $decor, $kopi, [9, 14, 24, null], 60, 'active', [120, 150, 170],
        '<p>Stoneware mug with a speckled glaze. Dishwasher and microwave safe.</p><blockquote>Holds 350 ml, about one generous cup.</blockquote>', null],
    ['Canvas Everyday Tote', 'DEMO-BAG-01', $bags, $north, [18, 28, 45, null], 25, 'active', [150, 140, 110],
        '<p>Heavy 12 oz canvas with a reinforced base and an inner pocket.</p><h3>Details</h3><ul><li>Fits a 14" laptop</li><li>Cotton straps</li></ul>', null],
    ['Brass Incense Holder', 'DEMO-DEC-01', $decor, $lumen, [6, 10, 19, null], 3, 'active', [180, 150, 70],
        '<p>Solid brass holder. <em>Only a few left</em>: handy for testing the low-stock alerts.</p>', null],
    ['Glass Bud Vase', 'DEMO-DEC-02', $decor, $lumen, [8, 12, 22, null], 0, 'active', [110, 160, 150],
        '<p>Hand-blown glass bud vase. Sold out: handy for testing the out-of-stock state.</p>', null],
    ['Walnut Serving Tray', 'DEMO-DEC-03', $decor, $kopi, [30, 48, 79, null], 12, 'draft', [110, 85, 70],
        '<p>Solid walnut tray. Saved as a <strong>draft</strong>, so it is not visible in the shop yet.</p>', null],
    ['Classic Cotton Tee', null, $tees, $north, [20, 38, 59, null], 0, 'active', [90, 120, 160],
        '<p>Mid-weight 100% cotton tee with a relaxed fit. Pre-washed, so it will not shrink.</p><ul><li>Unisex sizing</li><li>Machine washable</li></ul>',
        [['DEMO-TEE-S', 'Size', 'S', null, 18], ['DEMO-TEE-M', 'Size', 'M', null, 30], ['DEMO-TEE-L', 'Size', 'L', null, 22], ['DEMO-TEE-XL', 'Size', 'XL', '64', 9], ['DEMO-TEE-XXL', 'Size', 'XXL', '64', 0]]],
    ['Linen Cushion Cover', null, $decor, $lumen, [12, 20, 39, 33], 0, 'active', [160, 150, 120],
        '<p>Stonewashed linen cover with a hidden zip. Fits a 45 x 45 cm insert (not included).</p>',
        [['DEMO-CUS-SAND', 'Color', 'Sand', null, 20], ['DEMO-CUS-OLIVE', 'Color', 'Olive', null, 14], ['DEMO-CUS-NAVY', 'Color', 'Navy', null, 0]]],
    ['Heritage Hoodie', null, $tees, $north, [45, 70, 119, null], 0, 'active', [70, 90, 100],
        '<p>Brushed-fleece pullover hoodie. Choose a size and colour: each combination has its own stock.</p><h3>Care</h3><p>Wash cold, tumble dry low.</p>',
        [['DEMO-HOO-M-BLK', 'Size / Color', 'M / Black', null, 10], ['DEMO-HOO-L-BLK', 'Size / Color', 'L / Black', null, 8], ['DEMO-HOO-M-GRY', 'Size / Color', 'M / Grey', null, 6], ['DEMO-HOO-L-GRY', 'Size / Color', 'L / Grey', '125', 4]]],
    ['Scented Candle Trio', null, $candles, $lumen, [30, 48, 85, null], 0, 'active', [190, 120, 120],
        '<p>Three 80 g candles in one gift box. Pick a scent set.</p>',
        [['DEMO-TRI-FOREST', 'Set', 'Forest', null, 15], ['DEMO-TRI-CITRUS', 'Set', 'Citrus', null, 15], ['DEMO-TRI-FLORAL', 'Set', 'Floral', '89', 11]]],
];

$made = ['products' => 0, 'variations' => 0, 'images' => 0, 'skipped' => 0];
foreach ($cat as $n => [$name, $sku, $catId, $brandId, $price, $stock, $status, $rgb, $html, $vars]) {
    $slug = 'demo-' . Str::slug($name);
    if (DB::query('SELECT id FROM products WHERE slug = ? AND deleted_at IS NULL', [$slug])->fetchColumn()) {
        $made['skipped']++;
        continue;
    }
    [$base, $company, $retail, $disc] = $price;
    $fmt = fn($v) => $v === null ? null : number_format((float) $v, 2, '.', '');
    $pid = Product::insert(['weight_kg' => '0.800', 'width_cm' => '20', 'length_cm' => '30', 'height_cm' => '5', 
        'category_id' => $catId, 'brand_id' => $brandId, 'name' => $name, 'slug' => $slug, 'sku' => $sku,
        'description' => RichText::clean($html), 'base_price' => $fmt($base), 'company_base_price' => $fmt($company),
        'retail_price' => $fmt($retail), 'discount_price' => $fmt($disc), 'stock' => $stock, 'status' => $status,
    ]);
    if ($vars !== null) {
        $prices = ['base_price' => $fmt($base), 'company_base_price' => $fmt($company), 'retail_price' => $fmt($retail), 'discount_price' => $fmt($disc)];
        $rows = array_map(fn($v, $i) => ['id' => 0, 'sku' => $v[0], 'option' => $v[1], 'value' => $v[2], 'price' => $v[3] ?? '', 'stock' => (string) $v[4]], $vars, array_keys($vars));
        $checked = VariantService::validateRows($rows, $prices, $pid);
        if ($checked['errors'] !== []) {
            fwrite(STDERR, "Seed data for \"{$name}\" is invalid: " . json_encode($checked['errors']) . "\n");
            exit(1);
        }
        VariantService::saveRows($pid, $checked['rows']);
        $made['variations'] += count($checked['rows']);
        foreach (VariantService::variants($pid) as $v) { // opening stock, so Stock history starts complete
            if ((int) $v['stock'] > 0) {
                StockService::record($pid, (int) $v['id'], 0, (int) $v['stock'], 'initial');
            }
        }
    } elseif ($stock > 0) {
        StockService::record($pid, null, 0, $stock, 'initial');
    }
    if ($opts['images']) {
        $count = $vars !== null ? 3 : 2;
        for ($i = 0; $i < $count; $i++) {
            $path = demo_image($name, $rgb, $i);
            if ($path === null) {
                break;
            }
            DB::query('INSERT INTO product_images (product_id, path, is_primary, position) VALUES (?, ?, ?, ?)', [$pid, $path, $i === 0 ? 1 : 0, $i]);
            $made['images']++;
        }
    }
    $made['products']++;
}

// ---- sample orders: all NEW (pending) ------------------------------------------------------------------------
$people = [
    ['Aisyah Rahman', 'aisyah.rahman', '012-3456789', '12 Jalan Bukit 3', 'Taman Desa', 'Kuala Lumpur', 'Kuala Lumpur', '58100'],
    ['Daniel Tan', 'daniel.tan', '016-2233445', '8 Lorong Maju 2', null, 'Petaling Jaya', 'Selangor', '47301'],
    ['Nurul Huda', 'nurul.huda', '019-8877665', '45 Persiaran Seri', 'Seksyen 7', 'Shah Alam', 'Selangor', '40000'],
    ['Wei Ling Lim', 'weiling.lim', '017-5566778', '3 Jalan Gurney', null, 'George Town', 'Pulau Pinang', '10250'],
    ['Hafiz Abdullah', 'hafiz.abdullah', '011-23456789', '21 Jalan Dato Onn', null, 'Johor Bahru', 'Johor', '80000'],
    ['Priya Nair', 'priya.nair', '013-4455667', '77 Jalan Ipoh Lama', 'Taman Sentosa', 'Ipoh', 'Perak', '31400'],
    ['Farah Zulkifli', 'farah.zulkifli', '014-3322110', '9 Jalan Tuanku', null, 'Seremban', 'Negeri Sembilan', '70000'],
    ['Marcus Lee', 'marcus.lee', '018-9988776', '15 Jalan Kenari', 'Bandar Puchong Jaya', 'Puchong', 'Selangor', '47100'],
    ['Siti Aminah', 'siti.aminah', '010-7766554', '62 Lorong Kebun', null, 'Kota Bharu', 'Kelantan', '15000'],
    ['Jason Wong', 'jason.wong', '012-8899001', '5 Jalan Pelabuhan', null, 'Kuching', 'Sarawak', '93000'],
    ['Amirul Hakim', 'amirul.hakim', '019-1122334', '30 Jalan Bunga Raya', 'Taman Mutiara', 'Melaka', 'Melaka', '75000'],
    ['Chloe Ong', 'chloe.ong', '016-6655443', '18 Jalan Kiara', 'Mont Kiara', 'Kuala Lumpur', 'Kuala Lumpur', '50480'],
];
$notes = [null, null, 'Please gift wrap.', null, 'Call before delivery.', null, null, 'Leave with the guard house.', null, null, null, 'Gift: no price slip please.'];

$have = (int) DB::query('SELECT COUNT(*) FROM orders WHERE customer_email LIKE ?', ['%@' . DEMO_DOMAIN])->fetchColumn();
$placed = 0;
$states = config('states');
for ($i = $have; $i < $opts['orders']; $i++) {
    // Only products that can be ordered right now: a plain product with stock, or a product with a variation in stock.
    $pool = array_values(DB::query(
        "SELECT p.id FROM products p WHERE p.slug LIKE 'demo-%' AND p.status = 'active' AND p.deleted_at IS NULL AND (
            (NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL) AND p.stock > 0)
            OR EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL AND v.stock > 0))
         ORDER BY p.id"
    )->fetchAll(PDO::FETCH_COLUMN));
    $lines = [];
    if ($pool !== []) {
        foreach ((array) array_rand($pool, min(count($pool), mt_rand(1, 3))) as $at) {
            $pid = $pool[$at];
            $variants = DB::query('SELECT id, stock FROM product_variants WHERE product_id = ? AND deleted_at IS NULL AND stock > 0', [$pid])->fetchAll();
            if ($variants !== []) {
                $v = $variants[array_rand($variants)];
                $lines[] = [(int) $pid, (int) $v['id'], min(mt_rand(1, 3), (int) $v['stock'])];
            } else {
                $lines[] = [(int) $pid, null, min(mt_rand(1, 3), (int) DB::query('SELECT stock FROM products WHERE id = ?', [$pid])->fetchColumn())];
            }
        }
    }
    if ($lines === []) {
        break; // nothing left in stock to order
    }
    $p = $people[$i % count($people)];
    $suffix = $i >= count($people) ? (string) intdiv($i, count($people) + 0) : '';
    $email = $p[1] . $suffix . '@' . DEMO_DOMAIN;
    if (!in_array($p[6], $states, true)) {
        $p[6] = $states[0];
    }

    $pdo = DB::connection();
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', 'demo-seed-' . bin2hex(random_bytes(8)))]);
    $cartId = (int) $pdo->lastInsertId();
    foreach ($lines as [$pid, $vid, $qty]) {
        DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty) VALUES (?, ?, ?, ?)', [$cartId, $pid, $vid, $qty]);
    }
    try {
        $res = CheckoutService::place($cartId, [
            'name' => $p[0], 'email' => $email, 'phone' => $p[2], 'line1' => $p[3], 'line2' => $p[4] ?? '',
            'city' => $p[5], 'state' => $p[6], 'postcode' => $p[7], 'notes' => $notes[$i % count($notes)] ?? '',
        ], $i % 3 === 1 ? 'bank_transfer' : 'cod', null);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Could not place sample order " . ($i + 1) . ": " . $e->getMessage() . "\n");
        DB::query('DELETE FROM carts WHERE id = ?', [$cartId]);
        continue;
    }
    DB::query('DELETE FROM carts WHERE id = ?', [$cartId]);
    // Spread them over the last 7 days (newest first in the list), so dashboards and "oldest waiting" look real.
    $when = date('Y-m-d H:i:s', time() - mt_rand(600, 7 * 86400));
    DB::query('UPDATE orders SET created_at = ?, updated_at = ? WHERE id = ?', [$when, $when, $res['order_id']]);
    DB::query('UPDATE order_events SET created_at = ? WHERE order_id = ?', [$when, $res['order_id']]);
    $placed++;
}

$totalOrders = (int) DB::query('SELECT COUNT(*) FROM orders WHERE customer_email LIKE ?', ['%@' . DEMO_DOMAIN])->fetchColumn();
echo "Products added: {$made['products']} ({$made['variations']} variations, {$made['images']} images); already there: {$made['skipped']}.\n";
echo "Sample orders added: {$placed} (now {$totalOrders} demo orders, all New/pending).\n";
echo "Remove it all again with: php bin/seed-demo.php --remove\n";
