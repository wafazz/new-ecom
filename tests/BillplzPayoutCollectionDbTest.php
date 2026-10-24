<?php
// Billplz settings: the payment order collection ID (for paying affiliate withdrawals) is optional, checked, saved and shown. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\User;
use App\Services\BillplzSettings as B;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
putenv('APP_KEY=billplz-payout-test-key-0123456789abcdef0123');
DB::query("DELETE FROM settings WHERE `key` LIKE 'billplz.%'");
$admin = User::create('admin', 'Bp Admin', 'bp-admin@example.test', 'Passw0rd!x');
$form = fn(array $over = []): array => array_replace([
    'mode' => 'sandbox', 'collection_id' => 'bills-001', 'api_key' => '', 'x_signature_key' => '', 'payment_order_collection_id' => '',
], $over);

check('the payment order collection ID can be left empty', !isset(B::validate($form())['payment_order_collection_id']));
check('a value with spaces or symbols is refused', isset(B::validate($form(['payment_order_collection_id' => 'PO COL!']))['payment_order_collection_id']));
check('a value that is too short is refused', isset(B::validate($form(['payment_order_collection_id' => 'ab']))['payment_order_collection_id']));
check('a valid value is accepted', !isset(B::validate($form(['payment_order_collection_id' => 'PO-COL-001']))['payment_order_collection_id']));

$changed = B::save($form(['payment_order_collection_id' => 'PO-COL-001']), (int) $admin);
check('saving records the change', in_array('payment_order_collection_id', $changed, true));
check('the settings page shows the saved ID', B::overview()['payment_order_collection_id'] === 'PO-COL-001');
check('the bill collection ID is unchanged by it', B::overview()['collection_id'] === 'bills-001');
