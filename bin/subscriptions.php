<?php
declare(strict_types=1);

// Cron (hourly): php bin/subscriptions.php
// Places this hour's subscription orders and pauses any subscription whose last cycle is still unpaid.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\Modules;
use App\Services\SubscriptionService;

if (!Modules::enabled('subscriptions')) {
    echo "Subscriptions are switched off.\n";
    exit(0);
}
$r = SubscriptionService::runDue();
echo "Orders created: {$r['created']} · paused: {$r['paused']} · resumed: {$r['resumed']}\n";
