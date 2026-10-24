<?php
declare(strict_types=1);

// Cron (every minute): php bin/worker.php
// Sends queued emails and WhatsApp messages for up to 50 seconds, then exits. A second run that starts while one
// is still going does nothing. Options:
//   --status        show how many jobs are pending, running, done and failed
//   --retry-failed  put failed jobs back in the queue
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\Queue;

$opts = getopt('', ['status', 'retry-failed']);
if (isset($opts['status'])) {
    foreach (Queue::counts() as $status => $n) {
        echo str_pad($status, 10) . $n . "\n";
    }
    exit(0);
}
if (isset($opts['retry-failed'])) {
    echo 'Re-queued ' . Queue::retryFailed() . " failed job(s).\n";
    exit(0);
}

@mkdir(BASE_PATH . '/storage/cache', 0750, true);
$lock = fopen(BASE_PATH . '/storage/cache/worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another worker is already running.\n";
    exit(0);
}

try {
    $r = Queue::run(50);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Worker failed: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "Done {$r['done']}, waiting to retry {$r['retry']}, failed {$r['failed']}.\n";
