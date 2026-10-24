<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Applies database/migrations/*.sql that have not run yet (used by bin/migrate.php and the install wizard). */
final class Migrator
{
    /** @return string[] the migration files applied now (empty when up to date) */
    public static function run(): array
    {
        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            ran_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $done = array_column(Database::query('SELECT name FROM migrations')->fetchAll(), 'name');
        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            // MySQL DDL auto-commits, so a failed file can be partially applied; keep files small and single-purpose.
            $pdo->exec((string) file_get_contents($file));
            Database::query('INSERT INTO migrations (name) VALUES (?)', [$name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Names of migration files not applied yet (no changes made). @return string[] */
    public static function pending(): array
    {
        // A brand-new database has no migrations table yet: every file is pending.
        $exists = (int) Database::query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migrations'")->fetchColumn() > 0;
        $done = $exists ? array_column(Database::query('SELECT name FROM migrations')->fetchAll(), 'name') : [];
        return array_values(array_filter(array_map('basename', glob(BASE_PATH . '/database/migrations/*.sql') ?: []), fn($n) => !in_array($n, $done, true)));
    }
}
