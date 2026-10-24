<?php
// Dashboard maths against hand-built orders on known dates. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\User;
use App\Services\DashboardService as D;

check('ago(): just now / minutes / hours / days', D::ago(1000, 1030) === 'just now' && D::ago(1000, 1000 + 300) === '5 min ago' && D::ago(0, 3 * 3600 + 10) === '3 h ago' && D::ago(0, 2 * 86400 + 5) === '2 d ago' && D::ago(5000, 1000) === 'just now');

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
$admin = User::create('admin', 'Admin', 'a@dash.test', 'Passw0rd!x');
$m1 = User::create('member', 'Mem One', 'm1@dash.test', 'Passw0rd!x');
$p1 = Product::insert(['name' => 'Widget <b>', 'slug' => 'w', 'sku' => 'W1', 'base_price' => '50', 'company_base_price' => '70', 'retail_price' => '100', 'stock' => 20, 'status' => 'active']);
$p2 = Product::insert(['name' => 'Gizmo', 'slug' => 'g', 'sku' => 'G1', 'base_price' => '5', 'company_base_price' => '7', 'retail_price' => '10', 'stock' => 3, 'status' => 'active']);

$day = fn(int $ago) => date('Y-m-d 12:00:00', strtotime("-{$ago} days"));
$mk = function (int $ago, int $qty, string $status, string $method = 'cod', string $pay = 'unpaid', ?int $uid = null) use ($p1) {
    $total = 100 * $qty;
    DB::query("INSERT INTO orders (order_no, user_id, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, total, payment_method, payment_status, status, created_at)
               VALUES (?, ?, 'Buyer', 'b@x.test', '0123456789', 'l', 'c', 'Johor', '50000', ?, ?, ?, ?, ?, ?)",
        ['ORD-' . strtoupper(bin2hex(random_bytes(5))), $uid, $total, $total, $method, $pay, $status, date('Y-m-d 12:00:00', strtotime("-{$ago} days"))]);
    $id = (int) DB::connection()->lastInsertId();
    DB::query("INSERT INTO order_items (order_id, product_id, product_name, unit_price, retail_price, base_price, company_base_price, qty, line_total) VALUES (?, ?, 'Widget <b>', 100, 100, 50, 70, ?, ?)", [$id, $p1, $qty, $total]);
    return $id;
};
$mk(0, 1, 'completed', 'cod', 'paid', $m1);   // today
$mk(1, 2, 'processing', 'billplz', 'paid');   // yesterday
$cancelledPaid = $mk(1, 1, 'cancelled', 'billplz', 'paid'); // yesterday, cancelled: excluded from sales, needs a refund
$mk(10, 3, 'completed', 'cod', 'paid');       // inside 30d, outside 7d (and inside the previous 7d window)
$mk(40, 1, 'pending', 'cod');                 // previous 30d window; still pending

$a7 = D::admin(7);
$s = $a7['metrics']['sales'];
check('7d sales = today + yesterday, cancelled excluded (300)', $s['total'] === 300.0 && $a7['metrics']['orders']['total'] === 2);
check('7d previous period = the 10-days-ago order (300)', $s['prevTotal'] === 300.0 && $a7['metrics']['orders']['prevTotal'] === 1);
check('series are day-aligned: today last, yesterday before it', count($a7['labels']) === 7 && $s['cur'][6] === 100.0 && $s['cur'][5] === 200.0 && $s['cur'][0] === 0.0 && $a7['labels'][6] === date('Y-m-d'));
check('previous series aligned the same length', count($s['prev']) === 7 && array_sum($s['prev']) === 300.0);
check('gross profit = (unit - base) x qty, cancelled excluded (150)', $a7['metrics']['profit']['total'] === 150.0);
check('average order = sales / orders (150)', $a7['metrics']['aov']['total'] === 150.0);
$a30 = D::admin(30);
check('30d sales 600 vs previous window 100', $a30['metrics']['sales']['total'] === 600.0 && $a30['metrics']['sales']['prevTotal'] === 100.0);
check('unknown range falls back to 30 days', D::admin(13)['days'] === 30 && count(D::admin(13)['labels']) === 30);
check('new members counted in the period (admin + 1 member today = 1 member)', $a30['metrics']['customers']['total'] === 1);

$st = array_column($a30['status'], 'value', 'key');
check('status breakdown counts every status in the period (incl. cancelled, returned)', $st === ['pending' => 0, 'processing' => 1, 'shipped' => 0, 'completed' => 2, 'cancelled' => 1, 'returned' => 0]);
check('status rows link to the filtered order list', $a30['status'][0]['href'] === '/admin/orders?status=pending');
check('top products: units and sales summed, name kept as data', $a30['products'][0]['units'] === 6 && $a30['products'][0]['value'] === 600.0 && $a30['products'][0]['label'] === 'Widget <b>');
check('payment mix by method, cancelled excluded, biggest first', array_column($a30['payments'], 'value', 'label') === ['Cash on delivery' => 400.0, 'Online banking / card (Billplz)' => 200.0]);

$q = array_column($a30['attention'], null, 'key');
check('queue: refund (paid then cancelled) is listed first as critical', $a30['attention'][0]['key'] === 'refunds' && $a30['attention'][0]['tone'] === 'crit' && $q['refunds']['count'] === 1);
check('queue: pending order older than the window still counted, with its age', $q['pending']['count'] === 1 && preg_match('/^oldest placed (39|40) d ago$/', (string) $q['pending']['hint']) === 1);
check('queue: paid processing order is ready to ship', $q['ship']['count'] === 1);
check('queue: low stock lists the 3-left product only', $q['stock']['count'] === 1 && D::lowStock('/admin')['rows'][0]['label'] === 'Gizmo' && D::lowStock('/admin')['rows'][0]['stock'] === 3);
check('queue never contains zero-count items', array_filter($a30['attention'], fn($i) => $i['count'] < 1) === []);
DB::query("INSERT INTO shipments (order_id, tracking_number, service_level, weight_kg, delivery_date, delivery_timeslot) SELECT id, 'TRKDASH0001', 'Standard', 1, CURDATE(), '09:00-18:00' FROM orders WHERE status = 'processing'");
check('an order with a live shipment leaves the "ready to ship" queue', !isset(array_column(D::admin(30)['attention'], null, 'key')['ship']));

$ops = D::ops('/staff', false, false);
check('staff without permissions get an empty queue and no data', $ops['attention'] === [] && $ops['recent'] === [] && $ops['status'] === [] && $ops['lowStock']['count'] === 0);
$ops = D::ops('/staff', true, false);
check('staff with orders.view: no refunds/commissions, links use their base', !isset(array_column($ops['attention'], null, 'key')['refunds']) && str_starts_with($ops['attention'][0]['href'], '/staff/orders') && $ops['recent'][0]['href'] !== '');

$setup = D::admin(30)['setup'];
$byLabel = fn(string $needle) => array_values(array_filter($setup, fn($i) => str_contains($i['label'], $needle)))[0];
check('setup checklist: product done, gateways not yet', $byLabel('product')['done'] === true && $byLabel('Billplz')['done'] === false && $byLabel('NinjaVan')['done'] === false);
$rc = D::recent(8, '/admin');
check('recent orders: latest placed (highest id) first, epoch timestamps, link to the order', count($rc) === 5 && str_ends_with($rc[0]['href'], '/orders/' . DB::query('SELECT MAX(id) FROM orders')->fetchColumn()) && is_int($rc[0]['ts']) && $rc[0]['ts'] > 1700000000);

$mem = D::member($m1);
check('member: one order, spent excludes nothing cancelled here (100)', $mem['orders'] === 1 && $mem['spent'] === 100.0 && $mem['track'] === null);
$mk(0, 2, 'processing', 'cod', 'unpaid', $m1);
DB::query("INSERT INTO order_events (order_id, event, note) SELECT id, 'placed', 'x' FROM orders WHERE user_id = ? AND status = 'processing'", [$m1]);
$mem = D::member($m1);
check('member: active order gets a 4-step tracker at "processing"', $mem['track'] !== null && $mem['track']['current'] === 1 && count($mem['track']['steps']) === 4 && $mem['track']['steps'][0]['at'] !== null && $mem['track']['steps'][2]['at'] === null);
check('member: spent now 300; affiliate block absent until a code exists', $mem['spent'] === 300.0 && $mem['affiliate'] === null);
App\Services\AffiliateService::ensureCode($m1);
DB::query("INSERT INTO affiliate_clicks (affiliate_id, ip_hash, clicked_at) VALUES (?, 'x', NOW()), (?, 'y', NOW() - INTERVAL 2 DAY)", [$m1, $m1]);
$mem = D::member($m1);
check('member affiliate: 30-day click series with today last', $mem['affiliate'] !== null && count($mem['affiliate']['series']) === 30 && $mem['affiliate']['series'][29] === 1 && $mem['affiliate']['series'][27] === 1 && array_sum($mem['affiliate']['series']) === 2);
