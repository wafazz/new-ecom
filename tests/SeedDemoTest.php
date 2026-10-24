<?php
// bin/seed-demo.php: adds demo products + NEW orders, is repeatable, and removes only what it made. Runs only on `*_test` databases.
use App\Core\Database as DB;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
$run = function (string $args) {
    $out = [];
    exec('APP_ENV=local ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/bin/seed-demo.php') . ' ' . $args . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
$n = fn(string $sql) => (int) DB::query($sql)->fetchColumn();
$demoOrders = "SELECT COUNT(*) FROM orders WHERE customer_email LIKE '%@demo.example.test'";

$run('--remove');
$before = $n('SELECT COUNT(*) FROM products WHERE slug NOT LIKE "demo-%"');
[$code, $out] = $run('--orders=8');
check('seeding succeeds', $code === 0 && str_contains($out, 'Products added: 10'), $out);
check('10 demo products: 6 single and 4 with variations', $n("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%'") === 10 && $n("SELECT COUNT(*) FROM products p WHERE slug LIKE 'demo-%' AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL)") === 4);
check('every variation has its own stock and SKU', $n("SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id = v.product_id WHERE p.slug LIKE 'demo-%' AND (v.sku IS NULL OR v.stock < 0)") === 0 && $n("SELECT COUNT(DISTINCT v.stock) FROM product_variants v JOIN products p ON p.id = v.product_id WHERE p.slug LIKE 'demo-%'") > 3);
check('catalogue has categories (with a parent), brands, an active mix, a draft and a sold-out product', $n("SELECT COUNT(*) FROM categories WHERE slug LIKE 'demo-%' AND parent_id IS NOT NULL") === 4 && $n("SELECT COUNT(*) FROM brands WHERE slug LIKE 'demo-%'") === 3 && $n("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%' AND status = 'draft'") === 1 && $n("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%' AND sku IS NOT NULL AND stock = 0 AND status = 'active'") >= 1);
check('prices follow the rules (retail >= company >= base, discount <= retail)', $n("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%' AND (retail_price < company_base_price OR company_base_price < base_price OR (discount_price IS NOT NULL AND discount_price > retail_price))") === 0);
check('descriptions are sanitised rich text', $n("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%' AND description LIKE '<p>%'") === 10);
check('8 sample orders, ALL pending and unpaid (New orders only)', $n($demoOrders) === 8 && $n("SELECT COUNT(*) FROM orders WHERE customer_email LIKE '%@demo.example.test' AND status = 'pending' AND payment_status = 'unpaid'") === 8);
check('orders came through checkout: totals add up, history + snapshots exist', $n("SELECT COUNT(*) FROM orders o WHERE customer_email LIKE '%@demo.example.test' AND (o.total <> o.subtotal + o.shipping_fee OR o.subtotal <> (SELECT SUM(line_total) FROM order_items i WHERE i.order_id = o.id) OR NOT EXISTS (SELECT 1 FROM order_events e WHERE e.order_id = o.id AND e.event = 'placed'))") === 0 && $n("SELECT COUNT(*) FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.customer_email LIKE '%@demo.example.test' AND (i.base_price <= 0 OR i.company_base_price <= 0)") === 0);
check('stock was deducted by the orders and never goes negative', $n('SELECT COUNT(*) FROM products WHERE stock < 0') + $n('SELECT COUNT(*) FROM product_variants WHERE stock < 0') === 0 && $n("SELECT p.stock + COALESCE((SELECT SUM(qty) FROM order_items i WHERE i.product_id = p.id), 0) FROM products p WHERE sku = 'DEMO-CAN-01'") === 40);
check('orders use the demo email domain only; no leftover carts', $n("SELECT COUNT(*) FROM orders WHERE customer_email LIKE '%@demo.example.test'") === $n('SELECT COUNT(*) FROM orders WHERE id IN (SELECT order_id FROM order_items)') && $n('SELECT COUNT(*) FROM carts') === 0);

$img = DB::query("SELECT i.path FROM product_images i JOIN products p ON p.id = i.product_id WHERE p.slug LIKE 'demo-%'")->fetchAll(PDO::FETCH_COLUMN);
check('placeholder images exist on disk (when GD is available), first one is primary', !function_exists('imagecreatetruecolor') || ($img !== [] && count(array_filter($img, fn($p) => is_file(BASE_PATH . '/public/' . $p))) === count($img) && $n("SELECT COUNT(*) FROM products p WHERE slug LIKE 'demo-%' AND (SELECT COUNT(*) FROM product_images i WHERE i.product_id = p.id AND i.is_primary = 1) <> 1 AND EXISTS (SELECT 1 FROM product_images i WHERE i.product_id = p.id)") === 0));

[$code, $out] = $run('--orders=8');
check('running again changes nothing (no duplicates)', $code === 0 && str_contains($out, 'Products added: 0') && str_contains($out, 'Sample orders added: 0') && $n("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%'") === 10 && $n($demoOrders) === 8);
[$code, $out] = $run('--orders=11');
check('asking for more orders tops up to that number', $code === 0 && $n($demoOrders) === 11);

[$code, $out] = $run('--remove');
check('--remove deletes only the demo data', $code === 0 && $n("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%'") === 0 && $n("SELECT COUNT(*) FROM categories WHERE slug LIKE 'demo-%'") === 0 && $n("SELECT COUNT(*) FROM brands WHERE slug LIKE 'demo-%'") === 0 && $n($demoOrders) === 0 && $n('SELECT COUNT(*) FROM products WHERE slug NOT LIKE "demo-%"') === $before);
check('--remove also deletes the image files and leaves no orphans', count(array_filter($img, fn($p) => is_file(BASE_PATH . '/public/' . $p))) === 0 && $n('SELECT COUNT(*) FROM order_items WHERE order_id NOT IN (SELECT id FROM orders)') === 0);

[$code, $out] = (function () { $o = []; exec('APP_ENV=production ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/bin/seed-demo.php') . ' 2>&1', $o, $c); return [$c, implode("\n", $o)]; })();
check('refuses to run when APP_ENV=production', $code === 1 && str_contains($out, 'Refusing') && $n("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%'") === 0);
[$code] = $run('--nonsense');
check('unknown options are rejected', $code === 1);
