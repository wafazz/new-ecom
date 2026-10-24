<?php
// Unique visitors: browsers counted once per day, admin and bot requests skipped, windows and "online now". Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Core\Request;
use App\Services\VisitorService as V;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36';
$vidA = str_repeat('a', 32);
$vidB = str_repeat('b', 32);
$get = fn(string $path, string $method = 'GET') => new Request($method, $path, [], []);

$_COOKIE[V::COOKIE] = $vidA;
V::track($get('/products'));
V::track($get('/products/lure'));
check('a visitor is one row, however many pages they open', (int) DB::query('SELECT COUNT(*) FROM visitors')->fetchColumn() === 1 && (int) DB::query('SELECT COUNT(*) FROM visitor_days')->fetchColumn() === 1);
check('the browser cookie is stored only as a hash', DB::query('SELECT visitor_hash FROM visitors')->fetchColumn() === hash('sha256', $vidA));

$_COOKIE[V::COOKIE] = $vidB;
V::track($get('/'));
$s = V::stats();
check('two browsers today are two unique visitors', $s['today'] === 2 && $s['overall'] === 2 && $s['week'] === 2 && $s['month'] === 2);

V::track($get('/admin/orders'));
V::track($get('/staff/products'));
V::track($get('/uploads/products/a.png'));
V::track($get('/', 'POST'));
check('admin, staff, uploads and POST requests are not counted', V::stats()['overall'] === 2);

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$_COOKIE[V::COOKIE] = str_repeat('c', 32);
V::track($get('/'));
$_SERVER['HTTP_USER_AGENT'] = '';
V::track($get('/'));
check('bots and requests with no browser are not counted', V::stats()['overall'] === 2);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36';

// A visitor seen 10 days ago shows in the 30-day count, but not in today's or the 7-day count.
DB::query("INSERT INTO visitors (visitor_hash, user_id, first_seen, last_seen) VALUES (?, NULL, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY)", [str_repeat('d', 64)]);
DB::query("INSERT INTO visitor_days (day, visitor_hash) VALUES (CURDATE() - INTERVAL 10 DAY, ?), (CURDATE() - INTERVAL 9 DAY, ?)", [str_repeat('d', 64), str_repeat('d', 64)]);
$s = V::stats();
check('the 7-day and 30-day windows count each visitor once across days', $s['today'] === 2 && $s['week'] === 2 && $s['month'] === 3 && $s['overall'] === 3, json_encode($s));

// Online now: seen in the last five minutes only.
DB::query("UPDATE visitors SET last_seen = NOW() - INTERVAL 30 MINUTE WHERE visitor_hash = ?", [hash('sha256', $vidB)]);
$s = V::stats();
check('a visitor quiet for 30 minutes is not online', $s['online'] === 1 && $s['online_guests'] === 1 && $s['online_members'] === 0, json_encode($s));

// A signed-in member counts as online and as a member.
DB::query("UPDATE visitors SET user_id = 7 WHERE visitor_hash = ?", [hash('sha256', $vidA)]);
$s = V::stats();
check('a signed-in member online is counted as a member, not a guest', $s['online'] === 1 && $s['online_members'] === 1 && $s['online_guests'] === 0);

// A new cookie appears and is set for the browser that has none.
unset($_COOKIE[V::COOKIE]);
V::track($get('/'));
check('a browser with no cookie gets a new visitor id', V::stats()['overall'] === 4);

// The storefront badge shows live figures: online now, today, last 7 days and all time.
$live = V::live();
check('the storefront figures are online, today, last 7 days and all time', array_keys($live) === ['online', 'today', 'week', 'overall'] && $live['online'] === V::stats()['online'] && $live['today'] === V::stats()['today'] && $live['week'] === V::stats()['week'] && $live['overall'] === V::stats()['overall']);
