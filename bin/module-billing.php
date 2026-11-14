<?php
declare(strict_types=1);

// Cron (daily): php bin/module-billing.php
// Emails the shop's admins 7 days before a monthly or yearly module period ends. A module switches off by itself
// when its paid period passes, and back on when it is paid again.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\ModuleBillingService;

echo 'Sent ' . ModuleBillingService::remindDue() . " renewal reminder(s).\n";
