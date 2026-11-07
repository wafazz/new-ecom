<?php
// DB-backed J&T Express settings checks. Runs only when DB_NAME ends in `_test`.
use App\Core\Database as DB;
use App\Models\Setting;
use App\Models\User;
use App\Services\JntExpressSettings as J;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'jnt.%'");
putenv('APP_KEY=jnt-db-test-key-0123456789abcdef0123');
$admin = User::create('admin', 'Admin', 'a@jnt.test', 'Passw0rd!x');
$creds = ['mode' => 'sandbox', 'api_account' => 'jnt-acct-1', 'customer_code' => 'CUST001', 'private_key' => 'jnt-private-key-98765', 'password' => 'jnt-pass-4321'];
$none = ['private_key' => '', 'password' => ''];
$empty = J::overview();

check('empty form: account, customer code and both secrets required', array_keys(J::validate(['mode' => 'sandbox', 'api_account' => '', 'customer_code' => ''] + $none, $empty)) === ['api_account', 'customer_code', 'private_key', 'password']);
check('bad mode and account characters rejected', array_keys(J::validate(['mode' => 'x', 'api_account' => 'a b', 'customer_code' => 'C'] + ['private_key' => 'k-12345', 'password' => 'p-12345'], $empty)) === ['mode', 'api_account']);
check('complete credentials are valid', J::validate($creds, $empty) === []);
check('blank secrets allowed once they are saved', J::validate(['mode' => 'production', 'api_account' => 'jnt-acct-1', 'customer_code' => 'CUST001'] + $none, ['private_key_set' => true, 'private_key_unreadable' => false, 'password_set' => true, 'password_unreadable' => false]) === []);

$changed = J::save($creds, $admin);
check('first save stores every field that differs from the default', $changed === ['api_account', 'customer_code', 'private_key', 'password']);
check('Private Key and Password encrypted at rest', str_starts_with(Setting::get('jnt.private_key'), 'enc:v1:') && str_starts_with(Setting::get('jnt.password'), 'enc:v1:') && !str_contains(Setting::get('jnt.private_key'), 'jnt-private'));
$o = J::overview();
check('overview shows masked hints only', $o['private_key_hint'] === '••••8765' && $o['password_hint'] === '••••4321' && !str_contains(json_encode($o), 'jnt-private') && !str_contains(json_encode($o), 'jnt-pass'));
check('complete flag true once saved and readable', $o['complete'] === true);
$c = J::credentials();
check('credentials() decrypts for server-side use', $c !== null && $c['private_key'] === 'jnt-private-key-98765' && $c['password'] === 'jnt-pass-4321' && $c['mode'] === 'sandbox');

$again = J::save(['mode' => 'sandbox', 'api_account' => 'jnt-acct-1', 'customer_code' => 'CUST001', 'private_key' => '', 'password' => ''], $admin);
check('re-saving unchanged values with blank secrets changes nothing', $again === []);
$log = DB::query("SELECT detail FROM audit_logs WHERE action = 'jnt.update' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('audit log names fields but never secret values', is_string($log) && str_contains($log, 'private_key') && !str_contains($log, 'jnt-private') && !str_contains($log, 'jnt-pass'));
