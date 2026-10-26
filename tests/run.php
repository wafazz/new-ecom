<?php
declare(strict_types=1);

// Minimal runner (no PHPUnit): php tests/run.php
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

$GLOBALS['fail'] = 0;
$GLOBALS['pass'] = 0;
function check(string $name, bool $cond): void
{
    $cond ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $name . "\n";
}

/** Empty every data table (FK checks off) so DB tests start clean; only ever call after the `_test` guard. */
function reset_test_db(): void
{
    $pdo = App\Core\Database::connection();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['flash_sale_products', 'flash_sales', 'shipment_events', 'shipments','audit_logs', 'affiliate_withdrawal_items', 'affiliate_withdrawals', 'affiliate_commissions', 'affiliate_payouts', 'affiliate_clicks', 'wishlist_items', 'addresses', 'digital_grants', 'product_license_keys', 'order_events', 'order_items', 'orders', 'cart_items', 'carts', 'variant_option_values', 'product_option_values', 'product_options', 'stock_movements', 'product_variants', 'product_images', 'products', 'brands', 'visitors', 'visitor_days', 'email_log', 'email_campaigns', 'email_verifications', 'password_resets', 'email_unsubscribes', 'whatsapp_log', 'voucher_sends', 'vouchers', 'jobs', 'users'] as $t) {
        $pdo->exec("TRUNCATE TABLE `{$t}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    seed_default_shipping();
}

/** Default postage in settings, so checkout works in every DB test. Shipping tests clear it, then call this again. */
function seed_default_shipping(): void
{
    $pdo = App\Core\Database::connection();
    $pdo->exec("DELETE FROM settings WHERE `key` LIKE 'shipping.%'");
    $rates = ['zone1' => ['first' => '8.00', 'next' => '2.00', 'benchmark' => '100.00', 'cod_at_or_below' => '5', 'cod_above' => '3'], 'zone2' => ['first' => '15.00', 'next' => '5.00', 'benchmark' => '150.00', 'cod_at_or_below' => '6', 'cod_above' => '4']];
    $set = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)');
    foreach ($rates as $zone => $fields) {
        foreach ($fields as $k => $v) {
            $set->execute(["shipping.{$zone}.{$k}", $v]);
        }
    }
}

// TEST_ONLY=ReceiptServiceDbTest,SeedDemoTest runs just those files (handy for chasing an interaction between tests)
$only = array_filter(array_map('trim', explode(',', (string) getenv('TEST_ONLY'))));
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    if ($only !== [] && !in_array(basename($file, '.php'), $only, true)) {
        continue;
    }
    echo basename($file) . "\n";
    require $file;
}
echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
exit($GLOBALS['fail'] > 0 ? 1 : 0);
