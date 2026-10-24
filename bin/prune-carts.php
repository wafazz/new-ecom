<?php
declare(strict_types=1);

// Cron (daily): php bin/prune-carts.php  — removes anonymous carts untouched for 7 days, plus expired
// throttle rows (login_attempts, rate_hits: 1 day) and old affiliate clicks (90 days).
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

echo 'Pruned ' . App\Services\CartService::pruneGuestCarts() . " guest cart(s).\n";

use App\Core\Database;
foreach ([
    'login attempt' => 'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)',
    'rate hit' => 'DELETE FROM rate_hits WHERE hit_at < (NOW() - INTERVAL 1 DAY)',
    'affiliate click' => 'DELETE FROM affiliate_clicks WHERE clicked_at < (NOW() - INTERVAL 90 DAY)',
] as $label => $sql) {
    echo "Pruned " . Database::query($sql)->rowCount() . " {$label}(s).\n";
}
