<?php
declare(strict_types=1);

// Cron (daily, e.g. 03:10): php bin/retention.php [--logs-days=180] [--audit-days=365] [--visitor-days=400] [--app-log-days=90]
// Deletes email and WhatsApp logs older than --logs-days, audit log rows older than --audit-days, daily visitor
// counts older than --visitor-days, and app.log entries older than --app-log-days. Totals are kept (see Retention).
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\Retention;

$opts = getopt('', ['logs-days:', 'audit-days:', 'visitor-days:', 'app-log-days:']);
$logDays = max(1, (int) ($opts['logs-days'] ?? 180));
$auditDays = max(1, (int) ($opts['audit-days'] ?? 365));
$visitorDays = max(1, (int) ($opts['visitor-days'] ?? 400));
$appLogDays = max(1, (int) ($opts['app-log-days'] ?? 90));

try {
    foreach (Retention::run($logDays, $auditDays, $visitorDays) as $table => $n) {
        echo "Removed {$n} row(s) from {$table}.\n";
    }
    $entries = Retention::trimAppLog(BASE_PATH . '/storage/logs/app.log', $appLogDays);
    echo "Removed {$entries} app log entr" . ($entries === 1 ? 'y' : 'ies') . ".\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Retention failed: ' . $e->getMessage() . "\n");
    exit(1);
}
