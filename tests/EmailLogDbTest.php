<?php
// Email log: filters by type, result, address or subject, and date range; paging; bad filters are ignored. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Services\EmailLog as L;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();

$add = function (string $kind, string $to, string $subject, int $ok, int $daysAgo, ?int $order = null, ?string $error = null): void {
    DB::query(
        'INSERT INTO email_log (kind, to_email, subject, ok, error, order_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW() - INTERVAL ? DAY)',
        [$kind, $to, $subject, $ok, $error, $order, $daysAgo]
    );
};
$add('order', 'buyer1@example.test', 'Pesanan ORD-1 telah diterima', 1, 0, 1);
$add('order', 'buyer2@example.test', 'Pesanan ORD-2 telah diterima', 1, 0, 2);
$add('order', 'buyer3@example.test', 'Pesanan ORD-3 telah diterima', 1, 0, 3);
$add('order', 'buyer4@example.test', 'Pesanan ORD-4 telah diterima', 0, 0, 4, 'Resend is down');
$add('reset', 'reset-user@example.test', 'Tetapkan semula kata laluan anda', 1, 10);
$add('campaign', 'x@example.test', 'Promosi 50% off', 1, 0);
$add('verify', 'a_b@example.test', 'Sahkan alamat emel anda', 1, 0);

$all = fn(array $f = [], int $page = 1) => L::search($f, $page);
check('with no filters, every email is listed', $all()['total'] === 7);
check('the type filter keeps one type only', $all(['kind' => 'order'])['total'] === 4);
check('the result filter separates sent from failed', $all(['status' => 'failed'])['total'] === 1 && $all(['status' => 'sent'])['total'] === 6);
check('type and result together narrow the list', $all(['kind' => 'order', 'status' => 'sent'])['total'] === 3);
check('an address search finds that address', $all(['q' => 'reset-user'])['total'] === 1);
check('a subject search finds the subject', $all(['q' => 'Sahkan'])['total'] === 1);
check('a % in the search is literal, not a wildcard', $all(['q' => '%'])['total'] === 1);
check('a _ in the search is literal, not a wildcard', $all(['q' => '_'])['total'] === 1);
check('the date range from-date excludes older emails', $all(['from' => date('Y-m-d', strtotime('-5 days'))])['total'] === 6);
check('the date range to-date keeps only older emails', $all(['to' => date('Y-m-d', strtotime('-5 days'))])['total'] === 1);
check('an impossible date is ignored, not an error', $all(['from' => '2026-13-40'])['total'] === 7);
check('an unknown type is ignored, not an error', $all(['kind' => 'bogus'])['total'] === 7);
check('the failure message is kept', $all(['status' => 'failed'])['rows'][0]['error'] === 'Resend is down');

// Paging: 60 more rows make 67, so 50 on page 1 and 17 on page 2; a page past the end shows the last one.
for ($i = 0; $i < 60; $i++) {
    $add('test', "bulk{$i}@example.test", 'Test', 1, 0);
}
$p1 = $all([], 1);
$p2 = $all([], 2);
check('a page holds 50 emails', $p1['total'] === 67 && count($p1['rows']) === 50 && $p1['pages'] === 2);
check('the second page holds the rest', count($p2['rows']) === 17 && $p2['page'] === 2);
check('a page past the end shows the last page', $all([], 99)['page'] === 2);
