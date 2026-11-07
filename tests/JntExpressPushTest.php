<?php
// J&T Express push: payload, signature and availability checks. Pure checks always run; the
// blocker/create checks need the DB and run only when DB_NAME ends in `_test`.
use App\Core\Database as DB;
use App\Models\Setting;
use App\Models\User;
use App\Services\JntExpressSettings as J;
use App\Services\Shipping\JntExpressGateway as G;

check('digest = base64(md5(json . key, binary))', G::digest('{"a":1}', 'secretkey') === 'HHdePFgx3okBH0W/k6LWvg==');
check('password param is uppercase md5 of the plain password', G::passwordParam('abc') === '900150983CD24FB0D6963F7D28E17F72');

$sender = ['sender_name' => 'Lure Shop', 'sender_phone' => '012-3456789', 'sender_email' => 'ship@lure.test', 'sender_address1' => '1 Jalan Satu', 'sender_address2' => 'Unit 2', 'sender_postcode' => '50000', 'sender_city' => 'Kuala Lumpur', 'sender_state' => 'Kuala Lumpur'];
$order = [
    'order_no' => 'LS-1001', 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'total' => '85.00', 'notes' => '',
    'customer_name' => 'Ali Bin Ahmad', 'customer_phone' => '0123456789', 'customer_email' => 'ali@example.test',
    'ship_line1' => '12 Jalan Mawar', 'ship_line2' => '', 'ship_city' => 'Shah Alam', 'ship_state' => 'Selangor', 'ship_postcode' => '40000', 'ship_country' => 'MY',
];
$items = [
    ['product_name' => 'Hook Pack', 'variant_label' => 'Size 2', 'qty' => 2, 'unit_price' => '30.00', 'weight_kg' => '0.250'],
    ['product_name' => 'Rod', 'variant_label' => null, 'qty' => 1, 'unit_price' => '25.00', 'weight_kg' => '1.200'],
];
$biz = G::buildBizContent($order, $items, $sender, 'CUST001', 'pw');
check('bizContent builds for a complete COD order', is_array($biz));
check('COD: CC_CASH and codInfo carry the order total', $biz['payType'] === 'CC_CASH' && $biz['codInfo']['codValue'] === '85.00');
check('txlogisticId is the order number; password is md5 form', $biz['txlogisticId'] === 'LS-1001' && $biz['password'] === G::passwordParam('pw') && $biz['customerCode'] === 'CUST001');
check('package weight is the sum of qty x weight (kg)', $biz['packageInfo']['weight'] === '1.70' && $biz['packageInfo']['packageValue'] === '85.00');
check('item weight is sent in grams and item names carry the variant', $biz['items'][0]['weight'] === '250' && $biz['items'][0]['itemName'] === 'Hook Pack (Size 2)');
check('receiver and sender are Malaysian with postcodes', $biz['receiver']['countryCode'] === 'MYS' && $biz['receiver']['postCode'] === '40000' && $biz['sender']['address'] === '1 Jalan Satu Unit 2');
$paid = ['payment_method' => 'billplz', 'payment_status' => 'paid'] + $order;
$pb = G::buildBizContent($paid, $items, $sender, 'CUST001', 'pw');
check('prepaid: PP_PM and no codInfo', $pb['payType'] === 'PP_PM' && !isset($pb['codInfo']));
$noWeight = $items;
$noWeight[1]['weight_kg'] = null;
check('an item without weight blocks the build (nothing half-sent)', G::buildBizContent($order, $noWeight, $sender, 'CUST001', 'pw') === null);

// ---- DB-backed checks ------------------------------------------------------------------------
if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run J&T DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'jnt.%' OR `key` LIKE 'ninjavan.%'");
putenv('APP_KEY=jnt-push-test-key-0123456789abcdef0123');
$admin = User::create('admin', 'Admin', 'a@jntpush.test', 'Passw0rd!x');
$ninja = ['sender_name' => 'Lure Shop', 'sender_phone' => '012-3456789', 'sender_email' => 'ship@lure.test', 'sender_address1' => '1 Jalan Satu', 'sender_address2' => '', 'sender_postcode' => '50000', 'sender_city' => 'Kuala Lumpur', 'sender_state' => 'Kuala Lumpur'];
DB::query("INSERT INTO settings (`key`, `value`) VALUES ('ninjavan.sender_name','Lure Shop'),('ninjavan.sender_phone','012-3456789'),('ninjavan.sender_email','ship@lure.test'),('ninjavan.sender_address1','1 Jalan Satu'),('ninjavan.sender_postcode','50000'),('ninjavan.sender_city','Kuala Lumpur'),('ninjavan.sender_state','Kuala Lumpur')");
J::save(['enabled' => '0', 'mode' => 'sandbox', 'api_account' => '10001', 'customer_code' => 'CUST001', 'private_key' => 'jnt-private-key-98765', 'password' => 'jnt-pass-4321'], $admin);
DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES ('LS-T1', 'Ali', 'ali@example.test', '0123456789', '12 Jalan Mawar', 'Shah Alam', 'Selangor', '40000', 'MY', 85, 0, 85, 'cod', 'unpaid', 'processing')");
$orderId = (int) DB::connection()->lastInsertId();
$o = DB::query('SELECT * FROM orders WHERE id = ?', [$orderId])->fetch();

check('blocker: refuses while J&T is off', str_contains((string) G::blocker($o), 'not turned on'));
J::save(['enabled' => '1'] + ['mode' => 'sandbox', 'api_account' => '10001', 'customer_code' => 'CUST001', 'private_key' => '', 'password' => ''], $admin);
check('enabling with saved credentials is accepted and reported', J::enabled() === true);
check('blocker: clear once enabled, configured and the order is processing COD', G::blocker($o) === null);
DB::query("UPDATE orders SET status = 'pending' WHERE id = ?", [$orderId]);
check('blocker: order must be Processing', str_contains((string) G::blocker(DB::query('SELECT * FROM orders WHERE id = ?', [$orderId])->fetch()), 'Processing'));
DB::query("UPDATE orders SET status = 'processing' WHERE id = ?", [$orderId]);
$off = J::save(['enabled' => '0', 'mode' => 'sandbox', 'api_account' => '10001', 'customer_code' => 'CUST001', 'private_key' => '', 'password' => ''], $admin);
check('switching off is saved', $off === ['enabled'] && J::enabled() === false);
check('create() refuses when J&T is off, without calling J&T', G::create($orderId, $admin)['ok'] === false);
$log = DB::query("SELECT detail FROM audit_logs WHERE action = 'jnt.update' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('audit names the enabled flag, not secrets', is_string($log) && !str_contains($log, 'jnt-private') && !str_contains($log, 'jnt-pass'));

// ---- AWB layout (renders the label partial) ----------------------------------------------------
$awbOrder = ['order_no' => 'LS-AWB', 'customer_name' => 'Siti', 'customer_phone' => '0145401726', 'ship_line1' => '1 Jalan', 'ship_line2' => '', 'ship_city' => 'Seremban', 'ship_state' => 'Negeri Sembilan', 'ship_postcode' => '70450'];
$awbShip = ['carrier' => 'jnt', 'tracking_number' => '624916503332', 'sorting_code' => 'NSN-NSN312', 'third_sorting_code' => '012', 'weight_kg' => '0.50', 'cod_amount' => null, 'pickup_required' => 0, 'pickup_date' => null, 'service_level' => null];
$renderAwb = function (array $ship) use ($awbOrder, $sender): string {
    $order = $awbOrder; $items = null;
    ob_start();
    require BASE_PATH . '/app/Views/manage/orders/_awb_label.php';
    return ob_get_clean();
};
$jntHtml = $renderAwb($awbShip);
check('J&T AWB: J&T heading, Pengirim/Penerima and the sorting codes', str_contains($jntHtml, 'J&amp;T EXPRESS') && str_contains($jntHtml, 'Sender Details (Pengirim)') && str_contains($jntHtml, 'Recipient Details (Penerima)') && str_contains($jntHtml, 'NSN-NSN312') && str_contains($jntHtml, '012'));
check('J&T AWB: no NinjaVan wording on a J&T label', !str_contains($jntHtml, 'NinjaVan'));
$njHtml = $renderAwb(['carrier' => 'ninjavan', 'tracking_number' => 'NV123456', 'weight_kg' => '1.00', 'cod_amount' => null, 'pickup_required' => 0, 'pickup_date' => null, 'service_level' => 'Standard', 'sorting_code' => null, 'third_sorting_code' => null]);
check('NinjaVan AWB keeps its own headings', !str_contains($njHtml, 'J&amp;T') && str_contains($njHtml, 'Sender Details<') && str_contains($njHtml, 'Recipient Details<'));
