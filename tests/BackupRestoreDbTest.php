<?php
// Restore: a backup written by dump() restores into an empty database with the same rows and exact values.
// Works on a separate `*_test` database, never the live one. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\User;
use App\Services\Backup;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
User::create('admin', 'Restore Admin', 'restore@example.test', 'Passw0rd!x');
User::create('member', 'Restore Member', 'member@example.test', 'Passw0rd!x');
$tricky = "first line;\nsecond 'quoted' \\ back;\r\nthird";
DB::query("DELETE FROM settings WHERE `key` = 'test.tricky'");
DB::query('INSERT INTO settings (`key`, `value`) VALUES (?, ?)', ['test.tricky', $tricky]);

$dir = sys_get_temp_dir() . '/restore-test-' . bin2hex(random_bytes(4));
mkdir($dir);
$plain = $dir . '/backup.sql';
$gz = $dir . '/backup.sql.gz';
Backup::dump($plain, false);
Backup::dump($gz, true);

// A scratch database with the same server settings, emptied before each restore.
$config = config('database');
$target = substr((string) $config['name'], 0, -5) . '_restore_test';
$server = new PDO(sprintf('mysql:host=%s;port=%s;charset=%s', $config['host'], $config['port'], $config['charset']), $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$fresh = function () use ($server, $target, $config): PDO {
    $server->exec("DROP DATABASE IF EXISTS `{$target}`");
    $server->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    return new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $target, $config['charset']), $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};
$counts = function (PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $out[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    }
    ksort($out);
    return $out;
};
$source = $counts(DB::connection());

$pdo = $fresh();
$ok = true;
try {
    Backup::restore($plain, $pdo);
} catch (\Throwable $e) {
    $ok = false;
}
check('a plain backup restores without an error', $ok);
check('every table comes back with the same number of rows', $counts($pdo) === $source && $source['users'] === 2);
check('a value with a line break and semicolons comes back exactly', $pdo->query("SELECT `value` FROM settings WHERE `key` = 'test.tricky'")->fetchColumn() === $tricky);

$pdo = $fresh();
Backup::restore($gz, $pdo);
check('a gzipped backup restores the same rows', $counts($pdo) === $source);

$pdo = $fresh();
$notOurs = $dir . '/other.sql';
file_put_contents($notOurs, "-- some other dump\nSELECT 1;\n");
$refused = false;
try {
    Backup::restore($notOurs, $pdo);
} catch (\RuntimeException $e) {
    $refused = str_contains($e->getMessage(), 'not a backup made by this shop');
}
check('a file that is not a backup of this shop is refused and nothing runs', $refused && $counts($pdo) === []);

$cut = $dir . '/cut.sql';
file_put_contents($cut, substr((string) file_get_contents($plain), 0, (int) (filesize($plain) / 2)));
$pdo = $fresh();
$cutRefused = false;
try {
    Backup::restore($cut, $pdo);
} catch (\Throwable $e) {
    $cutRefused = true;
}
check('a backup cut off halfway is refused', $cutRefused);

$server->exec("DROP DATABASE IF EXISTS `{$target}`");
array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);
