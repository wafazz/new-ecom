<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class RateLimiter
{
    public static function tooMany(string $bucket, string $ip, int $max, int $minutes): bool
    {
        $n = Database::query(
            'SELECT COUNT(*) FROM rate_hits WHERE bucket = ? AND ip = ? AND hit_at > (NOW() - INTERVAL ' . (int) $minutes . ' MINUTE)',
            [$bucket, $ip]
        )->fetchColumn();
        return (int) $n >= $max;
    }

    public static function hit(string $bucket, string $ip): void
    {
        Database::query('INSERT INTO rate_hits (bucket, ip) VALUES (?, ?)', [$bucket, $ip]);
    }
}
