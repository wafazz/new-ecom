<?php
// Install wizard rules and database backups. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\User;
use App\Services\Backup;
use App\Services\Installer as I;
use App\Services\Migrator;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
$lock = sys_get_temp_dir() . '/install-test-' . bin2hex(random_bytes(4)) . '.lock';
putenv('INSTALL_LOCK=' . $lock);
putenv('INSTALL_TOKEN=' . str_repeat('t', 20));

// The wizard and its token.
check('a new database with no admin and no lock file is not installed yet', I::installed() === false);
check('the install token must be set and must match', I::tokenConfigured() && I::tokenMatches(str_repeat('t', 20)) && !I::tokenMatches('wrong'));
putenv('INSTALL_TOKEN=short');
check('a short install token is not accepted', !I::tokenConfigured() && !I::tokenMatches('short'));
putenv('INSTALL_TOKEN=' . str_repeat('t', 20));
$checks = I::checks();
check('the server checks cover PHP, extensions, the database and the folders', count($checks) >= 12 && array_column($checks, 'label') !== [] && in_array('Database connection', array_column($checks, 'label'), true));
check('a failed required check blocks the setup', I::passed([['label' => 'x', 'ok' => false, 'required' => true, 'detail' => '']]) === false && I::passed([['label' => 'x', 'ok' => false, 'required' => false, 'detail' => '']]) === true);
check('pending migrations are none on the test database', Migrator::pending() === []);

// Finishing: the first admin is created, the lock is written, and the wizard closes.
$base = ['admin_name' => 'Owner', 'admin_email' => 'owner@example.test', 'admin_password' => 'Passw0rd!x', 'admin_password_confirm' => 'Passw0rd!x', 'shipping_flat' => '8.00', 'commission_rate' => '10', 'guest_checkout' => '1'];
check('a bad commission rate is refused', I::finish(array_replace($base, ['commission_rate' => '250'])) !== null && count(User::byRole('admin')) === 0);
check('mismatched passwords are refused', I::finish(array_replace($base, ['admin_password_confirm' => 'nope'])) !== null && !is_file($lock));
check('setup creates the first admin and the shop defaults', I::finish($base) === null && count(User::byRole('admin')) === 1 && is_file($lock));
check('after setup the shop counts as installed, and setup cannot run again', I::installed() === true && I::finish($base) === 'This shop is already set up.');
check('the defaults were saved', DB::query("SELECT `value` FROM settings WHERE `key` = 'shipping_flat'")->fetchColumn() === '8.00');
@unlink($lock);
putenv('INSTALL_LOCK');

// Backups: a compressed dump with the tables and their rows.
$db = Backup::create(false);
check('a backup file is created', str_ends_with($db['db'], '.sql.gz') && is_file(Backup::dir() . '/' . $db['db']) && $db['files'] === null);
$text = '';
$gz = gzopen(Backup::dir() . '/' . $db['db'], 'rb');
while (!gzeof($gz)) {
    $text .= gzread($gz, 65536);
}
gzclose($gz);
check('the dump has the table definitions and the rows', str_contains($text, 'CREATE TABLE `users`') && str_contains($text, "INSERT INTO `users`") && str_contains($text, 'owner@example.test'));
check('the dump switches foreign key checks off and back on', str_contains($text, 'SET FOREIGN_KEY_CHECKS=0;') && str_contains($text, 'SET FOREIGN_KEY_CHECKS=1;'));
$listed = array_column(Backup::list(), 'name');
check('the backup is listed', in_array($db['db'], $listed, true));
check('only backup file names are served (no path tricks)', Backup::path('../config/app.php') === null && Backup::path('backup-x.sql') === null && Backup::path($db['db']) !== null);
check('a backup can be deleted', Backup::delete($db['db']) && Backup::path($db['db']) === null);

// tidy up
foreach (glob(Backup::dir() . '/backup-*') ?: [] as $f) {
    @unlink($f);
}
