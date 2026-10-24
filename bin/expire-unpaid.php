<?php
declare(strict_types=1);

// Cron (hourly): php bin/expire-unpaid.php
// Cancels online-payment (Billplz) orders that are still unpaid after UNPAID_HOLD_HOURS (default 24) and
// returns their stock. A payment that arrives later is still recorded (flagged "refund needed").
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\OrderService;

$hours = max(1, (int) env('UNPAID_HOLD_HOURS', 24));
$ids = Database::query(
    "SELECT id FROM orders WHERE payment_method = 'billplz' AND status = 'pending' AND payment_status = 'unpaid' AND created_at < (NOW() - INTERVAL {$hours} HOUR)"
)->fetchAll(PDO::FETCH_COLUMN);
$n = 0;
foreach ($ids as $id) {
    $n += OrderService::transition((int) $id, 'cancelled', null, "Auto-cancelled: unpaid after {$hours}h") === null ? 1 : 0;
}
echo "Expired {$n} unpaid order(s).\n";
