<?php
// Background jobs: running, waiting between retries, giving up, stale and live locks, and tidying. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Services\Queue;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
$job = fn(int $id) => DB::query('SELECT * FROM jobs WHERE id = ?', [$id])->fetch();
// A job that does nothing and always succeeds: an order that does not exist has no WhatsApp message to send.
$harmless = fn(): array => ['type' => 'whatsapp.status', 'payload' => ['order_id' => 999999, 'status' => 'pending']];

// A job with no handler is tried again, and given up after its last attempt.
$bad = Queue::push('no.such.job', ['x' => 1], 0, 2);
$r1 = Queue::run(5);
check('a job that fails is queued again, not lost', $r1['retry'] === 1 && $job($bad)['status'] === 'pending' && str_contains((string) $job($bad)['last_error'], 'No handler'));
check('the next try waits a minute', (int) DB::query('SELECT run_at > NOW() + INTERVAL 30 SECOND FROM jobs WHERE id = ' . $bad)->fetchColumn() === 1);
DB::query("UPDATE jobs SET run_at = NOW() WHERE id = ?", [$bad]);
$r2 = Queue::run(5);
check('after the last attempt it is marked failed', $r2['failed'] === 1 && $job($bad)['status'] === 'failed' && (int) $job($bad)['attempts'] === 2);
check('retrying failed jobs puts them back, with attempts reset', Queue::retryFailed() === 1 && $job($bad)['status'] === 'pending' && (int) $job($bad)['attempts'] === 0);

// A good job is done and removed from the due list.
DB::query('DELETE FROM jobs');
$good = Queue::push($harmless()['type'], $harmless()['payload']);
$r3 = Queue::run(5);
check('a job that succeeds is marked done', $r3['done'] === 1 && $job($good)['status'] === 'done');

// A job with a delay waits until it is due.
DB::query('DELETE FROM jobs');
$later = Queue::push($harmless()['type'], $harmless()['payload'], 300);
check('a job pushed with a delay is not run early', Queue::run(2)['done'] === 0 && $job($later)['status'] === 'pending');

// A job locked by a worker that died is taken again; one another worker is still running is left alone.
DB::query('DELETE FROM jobs');
$stale = Queue::push($harmless()['type'], $harmless()['payload']);
DB::query("UPDATE jobs SET status = 'running', locked_at = NOW() - INTERVAL 1 HOUR, attempts = 1 WHERE id = ?", [$stale]);
$r4 = Queue::run(5);
check('a job left running by a dead worker is taken again', $r4['done'] === 1 && $job($stale)['status'] === 'done');

DB::query('DELETE FROM jobs');
$live = Queue::push($harmless()['type'], $harmless()['payload']);
DB::query("UPDATE jobs SET status = 'running', locked_at = NOW() WHERE id = ?", [$live]);
check('a job another worker is running is left alone', Queue::run(2)['done'] === 0 && $job($live)['status'] === 'running');

// Old finished jobs are removed when the worker runs.
DB::query('DELETE FROM jobs');
DB::query("INSERT INTO jobs (type, payload, status, run_at, updated_at) VALUES ('whatsapp.status', '{}', 'done', NOW(), NOW() - INTERVAL 8 DAY)");
Queue::run(1);
check('finished jobs older than 7 days are removed', (int) DB::query("SELECT COUNT(*) FROM jobs WHERE status = 'done'")->fetchColumn() === 0);

$counts = Queue::counts();
check('the counts report every status', array_keys($counts) === ['pending', 'running', 'done', 'failed']);
