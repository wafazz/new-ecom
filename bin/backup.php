<?php
declare(strict_types=1);

// Cron (daily, e.g. 02:30): php bin/backup.php [--db-only] [--keep=14]
// Saves a database backup (plus the uploads and receipts zip, unless --db-only) to storage/backups and
// deletes the older ones, keeping the newest --keep database backups. Exits 1 if the backup fails.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\AuditService;
use App\Services\Backup;

$opts = getopt('', ['db-only', 'keep:']);
$keep = max(1, (int) ($opts['keep'] ?? 14));

try {
    $r = Backup::create(!isset($opts['db-only']));
} catch (\Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$name = $r['db'] . ($r['files'] ? ' + ' . $r['files'] : '');
AuditService::log(null, 'backup.scheduled', $name);
echo 'Saved ' . $name . '. Removed ' . count(Backup::prune($keep)) . " old backup file(s); keeping the newest {$keep}.\n";
