<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Unsubscribe from announcements. Each link carries the address and an HMAC of it under APP_KEY,
 * so only links this shop sent can unsubscribe anyone. The page asks first; mail clients post
 * straight to the same address for one-click unsubscribe (RFC 8058).
 */
final class UnsubscribeService
{
    /** A signed token for an address. */
    public static function token(string $email): string
    {
        $email = strtolower(trim($email));
        $sig = hash_hmac('sha256', 'unsubscribe|' . $email, (string) env('APP_KEY', ''));
        return rtrim(strtr(base64_encode($email), '+/', '-_'), '=') . '.' . $sig;
    }

    /** The address a token was made for, or null when the token is malformed or not ours. */
    public static function emailFor(string $token): ?string
    {
        if (preg_match('/^([A-Za-z0-9_-]{1,300})\.([a-f0-9]{64})$/', $token, $m) !== 1) {
            return null;
        }
        $b64 = strtr($m[1], '-_', '+/');
        $decoded = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
        if ($decoded === false || filter_var($decoded, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return hash_equals(self::token($decoded), $token) ? $decoded : null;
    }

    public static function url(string $email): string
    {
        return url('/unsubscribe/' . self::token($email));
    }

    public static function unsubscribe(string $email): void
    {
        Database::query('INSERT IGNORE INTO email_unsubscribes (email) VALUES (?)', [strtolower(trim($email))]);
    }

    /** Lower-cased addresses that unsubscribed, as a set (keys). @return array<string,true> */
    public static function set(): array
    {
        $out = [];
        foreach (Database::query('SELECT email FROM email_unsubscribes')->fetchAll(\PDO::FETCH_COLUMN) as $email) {
            $out[strtolower((string) $email)] = true;
        }
        return $out;
    }
}
