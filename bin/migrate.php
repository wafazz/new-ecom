<?php
declare(strict_types=1);

// Usage: php bin/migrate.php [--create-db]
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\Migrator;

if (in_array('--create-db', $argv, true)) {
    $c = config('database');
    $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset={$c['charset']}", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = preg_replace('/[^A-Za-z0-9_]/', '', $c['name']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database `{$name}` ready.\n";
}

$applied = Migrator::run();
foreach ($applied as $name) {
    echo "Migrated: {$name}\n";
}
echo $applied === [] ? "Nothing to migrate.\n" : 'Done (' . count($applied) . ").\n";
