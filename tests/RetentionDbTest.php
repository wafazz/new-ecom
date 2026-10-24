<?php
// Retention: old logs, audit rows and daily visitor counts go; recent rows and the all-time visitor totals stay. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Services\Retention;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();

DB::query("INSERT INTO email_log (kind, to_email, ok, created_at) VALUES ('order', 'old@example.test', 1, NOW() - INTERVAL 200 DAY), ('order', 'new@example.test', 1, NOW() - INTERVAL 5 DAY)");
DB::query("INSERT INTO whatsapp_log (status_key, phone, ok, created_at) VALUES ('paid', '60123456789', 1, NOW() - INTERVAL 200 DAY), ('paid', '60198765432', 1, NOW() - INTERVAL 5 DAY)");
DB::query("INSERT INTO audit_logs (action, ip, created_at) VALUES ('old.action', '10.0.0.1', NOW() - INTERVAL 400 DAY), ('new.action', '10.0.0.2', NOW() - INTERVAL 5 DAY)");
DB::query("INSERT INTO visitors (visitor_hash, first_seen, last_seen) VALUES (?, NOW() - INTERVAL 500 DAY, NOW() - INTERVAL 500 DAY)", [str_repeat('a', 64)]);
DB::query("INSERT INTO visitor_days (day, visitor_hash) VALUES (CURDATE() - INTERVAL 500 DAY, ?), (CURDATE() - INTERVAL 5 DAY, ?)", [str_repeat('a', 64), str_repeat('b', 64)]);

$removed = Retention::run(180, 365, 400);
check('old email log rows are removed and recent ones kept', $removed['email_log'] === 1 && (int) DB::query('SELECT COUNT(*) FROM email_log')->fetchColumn() === 1);
check('old WhatsApp log rows (with phone numbers) are removed', $removed['whatsapp_log'] === 1 && DB::query("SELECT phone FROM whatsapp_log")->fetchColumn() === '60198765432');
check('old audit rows (with IP addresses) are removed', $removed['audit_logs'] === 1 && DB::query("SELECT action FROM audit_logs")->fetchColumn() === 'new.action');
check('visitor day rows past their limit are removed', $removed['visitor_days'] === 1 && (int) DB::query('SELECT COUNT(*) FROM visitor_days')->fetchColumn() === 1);
check('the all-time visitor totals are kept (hashes only)', (int) DB::query('SELECT COUNT(*) FROM visitors')->fetchColumn() === 1);
check('a second run finds nothing left to remove', array_sum(Retention::run(180, 365, 400)) === 0);
