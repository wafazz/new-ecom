<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Keeps personal data for a limited time: email and WhatsApp logs (addresses and phone numbers), audit
 * log (IP addresses), daily visitor counts, and the app error log. Totals that hold no personal data stay:
 * the visitors table keeps one hashed row per browser, so the all-time unique count is unchanged.
 */
final class Retention
{
    /** @return array<string,int> rows removed per table */
    public static function run(int $logDays, int $auditDays, int $visitorDays): array
    {
        $tables = [
            'email_log' => "DELETE FROM email_log WHERE created_at < NOW() - INTERVAL {$logDays} DAY",
            'whatsapp_log' => "DELETE FROM whatsapp_log WHERE created_at < NOW() - INTERVAL {$logDays} DAY",
            'audit_logs' => "DELETE FROM audit_logs WHERE created_at < NOW() - INTERVAL {$auditDays} DAY",
            'visitor_days' => "DELETE FROM visitor_days WHERE day < CURDATE() - INTERVAL {$visitorDays} DAY",
        ];
        $removed = [];
        foreach ($tables as $table => $sql) {
            $removed[$table] = Database::query($sql)->rowCount();
        }
        return $removed;
    }

    /**
     * Remove app.log entries older than $days. An entry starts at a line like "[2026-10-24T10:00:00+08:00] ..."
     * and includes the stack trace lines under it. Lines before the first entry are kept.
     * Written in one go, so entries added while this runs (a few milliseconds) can be lost; run it when the shop is quiet.
     * @return int entries removed
     */
    public static function trimAppLog(string $file, int $days, ?int $now = null): int
    {
        if (!is_file($file)) {
            return 0;
        }
        $cutoff = ($now ?? time()) - $days * 86400;
        $kept = '';
        $removed = 0;
        $dropping = false;
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\[([^\]]+)\]/', $line, $m) === 1 && strtotime($m[1]) !== false) {
                $dropping = strtotime($m[1]) < $cutoff;
                $removed += $dropping ? 1 : 0;
            }
            if (!$dropping) {
                $kept .= $line . "\n";
            }
        }
        if ($removed > 0) {
            $tmp = $file . '.tmp';
            if (file_put_contents($tmp, $kept, LOCK_EX) === false || !rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('The app log could not be trimmed.');
            }
        }
        return $removed;
    }
}
