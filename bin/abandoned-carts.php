<?php
declare(strict_types=1);

// Cron (every 10 minutes): php bin/abandoned-carts.php
// Saves the held items of signed-in members' carts, then emails a restore link to carts idle for two hours.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\AbandonedCartService;
use App\Services\Modules;

if (!Modules::enabled('abandoned_cart_emails')) {
    echo "Abandoned cart emails are switched off.\n";
    exit(0);
}
echo 'Saved ' . AbandonedCartService::snapshot() . " cart snapshot(s).\n";
echo 'Sent ' . AbandonedCartService::remind() . " reminder(s).\n";
