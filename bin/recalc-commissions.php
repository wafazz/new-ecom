<?php
declare(strict_types=1);

// One-time after the commission fix: recalculate pending and approved affiliate commissions with the current formula
// (items after any discount, shipping not deducted again). Paid parts are never changed.
//   php bin/recalc-commissions.php          list what would change; nothing is written
//   php bin/recalc-commissions.php --apply  write the changes
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\AffiliateService;
use App\Services\PricingService;

$apply = in_array('--apply', $argv, true);
$r = AffiliateService::recalculateOpen($apply, null);

foreach ($r['changes'] as $c) {
    echo sprintf("commission %d (%s): RM%s -> RM%s\n", $c['id'], $c['status'], PricingService::format($c['old']), PricingService::format($c['new']));
}
echo sprintf(
    "%s %d commission(s). Unchanged: %d. Skipped (part already paid): %d.\n",
    $apply ? 'Updated' : 'Would update',
    count($r['changes']),
    $r['unchanged'],
    $r['skipped']
);
if (!$apply && $r['changes'] !== []) {
    echo "Run again with --apply to write these changes.\n";
}
