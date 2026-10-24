<?php
declare(strict_types=1);

// Usage: php bin/migrate.php [--create-db]
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Database;

if (in_array('--create-db', $argv, true)) {
    $c = config('database');
    $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset={$c['charset']}", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = preg_replace('/[^A-Za-z0-9_]/', '', $c['name']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database `{$name}` ready.\n";
}

$pdo = Database::connection();
$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    ran_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$done = array_column(Database::query('SELECT name FROM migrations')->fetchAll(), 'name');
$files = glob(BASE_PATH . '/database/migrations/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) {
        continue;
    }
    // MySQL DDL auto-commits, so a failed file can be partially applied; keep files small and single-purpose.
    $pdo->exec((string) file_get_contents($file));
    Database::query('INSERT INTO migrations (name) VALUES (?)', [$name]);
    echo "Migrated: {$name}\n";
    $ran++;
}
echo $ran === 0 ? "Nothing to migrate.\n" : "Done ({$ran}).\n";
