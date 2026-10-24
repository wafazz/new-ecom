<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Database backups (a compressed SQL dump written with PHP, so no mysqldump is needed) and, when the server has
 * ZipArchive, a zip of the uploaded images and receipts. Backups live in storage/backups (never public).
 */
final class Backup
{
    private const NAME = '/^backup-\d{8}-\d{6}(-files)?\.(sql\.gz|sql|zip)$/';
    private const CHUNK = 500;

    public static function dir(): string
    {
        return BASE_PATH . '/storage/backups';
    }

    /** @return array{db:string, files:?string} the file names written */
    public static function create(bool $withFiles): array
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('The backup folder cannot be created.');
        }
        $stamp = date('Ymd-His');
        $gz = extension_loaded('zlib');
        $db = "backup-{$stamp}" . ($gz ? '.sql.gz' : '.sql');
        self::dump($dir . '/' . $db, $gz);

        $files = null;
        if ($withFiles && class_exists(\ZipArchive::class)) {
            $files = "backup-{$stamp}-files.zip";
            self::zipFiles($dir . '/' . $files);
        }
        return ['db' => $db, 'files' => $files];
    }

    /** @return array<int,array{name:string, size:int, created:int}> newest first */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/backup-*') ?: [] as $file) {
            $name = basename($file);
            if (preg_match(self::NAME, $name) !== 1 || !is_file($file)) {
                continue;
            }
            $out[] = ['name' => $name, 'size' => (int) filesize($file), 'created' => (int) filemtime($file)];
        }
        usort($out, fn($a, $b) => $b['created'] <=> $a['created'] ?: strcmp($b['name'], $a['name']));
        return $out;
    }

    /** The full path of a backup file, or null when the name is not one of ours. */
    public static function path(string $name): ?string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return null;
        }
        $file = self::dir() . '/' . $name;
        return is_file($file) ? $file : null;
    }

    public static function delete(string $name): bool
    {
        $file = self::path($name);
        return $file !== null && @unlink($file);
    }

    private static function dump(string $file, bool $gz): void
    {
        $pdo = Database::connection();
        $out = $gz ? gzopen($file, 'wb9') : fopen($file, 'wb');
        if ($out === false) {
            throw new \RuntimeException('The backup file cannot be written.');
        }
        $w = fn(string $s) => $gz ? gzwrite($out, $s) : fwrite($out, $s);
        $w("-- Lure Shop database backup, " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach (Database::query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $t = str_replace('`', '', (string) $table);
            $create = Database::query("SHOW CREATE TABLE `{$t}`")->fetch();
            $w("DROP TABLE IF EXISTS `{$t}`;\n" . $create['Create Table'] . ";\n");
            for ($offset = 0; ; $offset += self::CHUNK) {
                $rows = Database::query("SELECT * FROM `{$t}` LIMIT " . self::CHUNK . " OFFSET {$offset}")->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows === []) {
                    break;
                }
                $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $values = array_map(fn($row) => '(' . implode(', ', array_map(
                    fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    $row
                )) . ')', $rows);
                $w("INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $values) . ";\n");
                if (count($rows) < self::CHUNK) {
                    break;
                }
            }
            $w("\n");
        }
        $w("SET FOREIGN_KEY_CHECKS=1;\n");
        $gz ? gzclose($out) : fclose($out);
    }

    private static function zipFiles(string $file): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The files backup cannot be written.');
        }
        foreach (['public/uploads', 'storage/receipts'] as $folder) {
            $root = BASE_PATH . '/' . $folder;
            if (!is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $zip->addFile($f->getPathname(), $folder . '/' . substr($f->getPathname(), strlen($root) + 1));
                }
            }
        }
        $zip->close();
    }
}
