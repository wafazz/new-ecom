<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Background jobs. Work that talks to a slow outside service (email, WhatsApp) is pushed here during a request
 * and done by bin/worker.php. A failed job is retried with a growing wait (1, 2, 4, 8 ... minutes) until
 * max_attempts; then it stays as failed until someone retries it.
 */
final class Queue
{
    public const MAX_ATTEMPTS = 5;
    private const STALE_MINUTES = 10;   // a job locked longer than this belonged to a worker that died
    private const MAX_WAIT_SECONDS = 3600;

    /** Add a job. Errors propagate: use this when the caller must know the job was not saved. */
    public static function push(string $type, array $payload, int $delaySeconds = 0, int $maxAttempts = self::MAX_ATTEMPTS): int
    {
        Database::query(
            'INSERT INTO jobs (type, payload, max_attempts, run_at) VALUES (?, ?, ?, NOW() + INTERVAL ' . max(0, $delaySeconds) . ' SECOND)',
            [$type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $maxAttempts]
        );
        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Add a job from inside a request that has already done its real work (an order placed, a status changed).
     * A queue problem is logged, never shown to the customer and never undoes that work.
     */
    public static function later(string $type, array $payload): void
    {
        try {
            self::push($type, $payload);
        } catch (\Throwable $e) {
            @file_put_contents(BASE_PATH . '/storage/logs/app.log', '[' . date('c') . "] queue ({$type}): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Run due jobs one at a time until none are due or $seconds have passed.
     * @return array{done:int, retry:int, failed:int}
     */
    public static function run(int $seconds = 50): array
    {
        $counts = ['done' => 0, 'retry' => 0, 'failed' => 0];
        self::releaseStale();
        $deadline = time() + $seconds;
        while (time() < $deadline) {
            $job = self::claim();
            if ($job === null) {
                break;
            }
            $counts[self::process($job)]++;
        }
        self::tidy();
        return $counts;
    }

    /** Failed jobs go back in the queue with their attempts reset. @return int jobs re-queued */
    public static function retryFailed(): int
    {
        return Database::query("UPDATE jobs SET status = 'pending', attempts = 0, run_at = NOW(), last_error = NULL WHERE status = 'failed'")->rowCount();
    }

    /** @return array<string,int> jobs per status */
    public static function counts(): array
    {
        $out = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        foreach (Database::query('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status')->fetchAll() as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** Take the next due job. Another worker may take it first; then look again. */
    private static function claim(): ?array
    {
        for ($tries = 0; $tries < 20; $tries++) {
            $row = Database::query("SELECT id FROM jobs WHERE status = 'pending' AND run_at <= NOW() ORDER BY run_at, id LIMIT 1")->fetch();
            if (!$row) {
                return null;
            }
            $taken = Database::query("UPDATE jobs SET status = 'running', attempts = attempts + 1, locked_at = NOW() WHERE id = ? AND status = 'pending'", [(int) $row['id']])->rowCount();
            if ($taken === 1) {
                return Database::query('SELECT * FROM jobs WHERE id = ?', [(int) $row['id']])->fetch() ?: null;
            }
        }
        return null;
    }

    /** @return string 'done' | 'retry' | 'failed' */
    private static function process(array $job): string
    {
        $handler = self::handlers()[$job['type']] ?? null;
        $error = 'The job did not finish.';
        try {
            $ok = $handler !== null && (bool) $handler(json_decode((string) $job['payload'], true) ?: []);
            if ($handler === null) {
                $error = 'No handler for this job type.';
            }
        } catch (\Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 255);
        }

        if ($ok) {
            Database::query("UPDATE jobs SET status = 'done', locked_at = NULL, last_error = NULL WHERE id = ?", [(int) $job['id']]);
            return 'done';
        }
        if ((int) $job['attempts'] < (int) $job['max_attempts']) {
            $wait = min(self::MAX_WAIT_SECONDS, 60 * (2 ** ((int) $job['attempts'] - 1)));
            Database::query("UPDATE jobs SET status = 'pending', locked_at = NULL, last_error = ?, run_at = NOW() + INTERVAL {$wait} SECOND WHERE id = ?", [$error, (int) $job['id']]);
            return 'retry';
        }
        Database::query("UPDATE jobs SET status = 'failed', locked_at = NULL, last_error = ? WHERE id = ?", [$error, (int) $job['id']]);
        return 'failed';
    }

    private static function releaseStale(): void
    {
        Database::query("UPDATE jobs SET status = 'pending', locked_at = NULL WHERE status = 'running' AND locked_at < NOW() - INTERVAL " . self::STALE_MINUTES . ' MINUTE');
    }

    /** Finished jobs go after 7 days, failed ones after 30 (their payloads hold customer addresses and messages). */
    private static function tidy(): void
    {
        Database::query("DELETE FROM jobs WHERE status = 'done' AND updated_at < NOW() - INTERVAL 7 DAY");
        Database::query("DELETE FROM jobs WHERE status = 'failed' AND updated_at < NOW() - INTERVAL 30 DAY");
    }

    /** What each job type does. A handler returns true when the job is finished, false to try again later. */
    private static function handlers(): array
    {
        return [
            'email.batch' => fn(array $p): bool => EmailService::deliverBatch($p),
            'email.order_created' => fn(array $p): bool => EmailService::orderCreatedSend((int) $p['order_id'], (string) $p['track_url']),
            'whatsapp.status' => function (array $p): bool {
                // WhatsAppService logs a provider rejection itself, so the job is finished either way.
                WhatsAppService::notifyStatus((int) $p['order_id'], (string) $p['status']);
                return true;
            },
        ];
    }
}
