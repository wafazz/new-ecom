<?php
// DB-backed NinjaVan settings checks. Runs only when DB_NAME ends in `_test`.
use App\Core\Database as DB;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NinjaVanSettings as N;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'ninjavan.%'");
putenv('APP_KEY=ninjavan-db-test-key-0123456789abcdef0123');
$admin = User::create('admin', 'Admin', 'a@nv.test', 'Passw0rd!x');
$sender = ['sender_name' => 'Lure Shop', 'sender_phone' => '012-3456789', 'sender_email' => 'ship@lure.test', 'sender_address1' => '1 Jalan Satu', 'sender_address2' => '', 'sender_postcode' => '50000', 'sender_city' => 'Kuala Lumpur', 'sender_state' => 'Kuala Lumpur'];
$none = array_fill_keys(N::SENDER, '');
$creds = ['mode' => 'sandbox', 'country' => 'MY', 'client_id' => 'nv-client-1', 'client_secret' => 'nv-secret-123456'];

check('empty form: Client ID + Key required', array_keys(N::validate(['mode' => 'sandbox', 'country' => 'MY', 'client_id' => '', 'client_secret' => ''] + $none)) === ['client_id', 'client_secret']);
check('bad mode / country / id / key rejected', array_keys(N::validate(['mode' => 'x', 'country' => 'ZZ', 'client_id' => 'a b', 'client_secret' => 'short'] + $none)) === ['mode', 'country', 'client_id', 'client_secret']);
check('credentials alone are valid (sender may be blank)', N::validate($creds + $none) === []);
check('half-filled sender: every other required field flagged', array_keys(N::validate($creds + ['sender_name' => 'X'] + $none)) === ['sender_phone', 'sender_email', 'sender_address1', 'sender_city', 'sender_postcode', 'sender_state']);
check('full MY sender is valid', N::validate($creds + $sender) === []);
check('MY postcode must be 5 digits and state from the list', array_keys(N::validate($creds + ['sender_postcode' => '123', 'sender_state' => 'Narnia'] + $sender)) === ['sender_postcode', 'sender_state']);
check('non-MY country accepts a free-text state and its own postcode shape', N::validate(['country' => 'SG'] + $creds + ['sender_postcode' => '018956', 'sender_state' => 'Singapore'] + $sender) === []);
check('bad email / phone flagged', array_keys(N::validate($creds + ['sender_email' => 'nope', 'sender_phone' => 'abc'] + $sender)) === ['sender_phone', 'sender_email']);

$changed = N::save($creds + $sender, $admin);
check('first save stores credentials and sender', in_array('client_id', $changed, true) && in_array('client_secret', $changed, true) && in_array('sender_name', $changed, true));
$raw = Setting::get('ninjavan.client_secret');
check('Client Key is encrypted at rest', str_starts_with($raw, 'enc:v1:') && !str_contains($raw, 'nv-secret'));
$o = N::overview();
check('overview exposes only a masked hint, never the key', $o['client_secret_hint'] === '••••3456' && !str_contains(json_encode($o), 'nv-secret'));
$c = N::credentials();
check('credentials() decrypts for server-side use; base URL from mode+override-free', $c['client_secret'] === 'nv-secret-123456' && $c['base_url'] === 'https://api-sandbox.ninjavan.co' && $c['country'] === 'MY');
check('sender() returns the pickup address once complete', N::sender()['sender_postcode'] === '50000' && N::sender()['country'] === 'MY');

check('blank key keeps the stored one', N::validate(['client_secret' => ''] + $creds + $sender) === [] && N::save(['client_secret' => ''] + $creds + ['sender_city' => 'Petaling Jaya'] + $sender, $admin) === ['sender_city'] && N::credentials()['client_secret'] === 'nv-secret-123456');
check('switching mode / country / client id without the key is refused', array_keys(N::validate(['mode' => 'production', 'client_secret' => ''] + $creds + $sender)) === ['client_secret'] && array_keys(N::validate(['country' => 'SG', 'client_secret' => ''] + $creds + $sender)) === ['client_secret'] && array_keys(N::validate(['client_id' => 'other-id', 'client_secret' => ''] + $creds + $sender)) === ['client_secret']);

Setting::set('ninjavan.verified', 'ok'); Setting::set('ninjavan.verified_at', '2026-10-22 10:00:00');
N::save(['client_secret' => ''] + $creds + ['sender_name' => 'Renamed Shop'] + $sender, $admin);
check('editing only the sender keeps the last passing test', Setting::get('ninjavan.verified') === 'ok');
N::save(['client_secret' => 'rotated-key-99999'] + $creds + $sender, $admin);
check('changing the Client Key clears the last test result', Setting::get('ninjavan.verified') === '');

$log = json_encode(AuditService::recent('ninjavan.', 50));
check('audit records field names but never a key value', str_contains($log, 'client_secret') && !str_contains($log, 'nv-secret') && !str_contains($log, 'rotated-key'));

putenv('APP_KEY=a-different-key-0123456789abcdef0123456789');
check('after APP_KEY change: flagged unreadable, credentials() null, key required again', N::overview()['unreadable'] === true && N::credentials() === null && isset(N::validate(['client_secret' => ''] + $creds + $sender)['client_secret']));
check('the AWB items setting is off by default and saved as a yes/no choice', N::showItemsOnAwb() === false && N::overview()['awb_show_items'] === false);
N::save(['awb_show_items' => '1'] + $creds + $sender, $admin);
check('turning on the AWB items setting is saved and logged', N::showItemsOnAwb() === true && N::overview()['awb_show_items'] === true && (int) DB::query("SELECT COUNT(*) FROM audit_logs WHERE action = 'ninjavan.update' AND detail LIKE '%awb_show_items%'")->fetchColumn() >= 1);
N::save(['awb_show_items' => '0'] + $creds + $sender, $admin);
check('turning it off again is saved', N::showItemsOnAwb() === false);
