<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;

/**
 * Unique visitors and who is online now. A visitor is a browser: a random cookie, stored only as a SHA-256 hash.
 * Admin/staff pages, uploads and obvious bots are not counted.
 */
final class VisitorService
{
    public const COOKIE = 'shop_vid';
    public const ONLINE_MINUTES = 5;
    private const SKIP_PREFIXES = ['/admin', '/staff', '/assets', '/uploads', '/health'];
    private const BOT = '/bot|crawl|spider|slurp|wget|curl|monitor|pingdom|uptime|preview|facebookexternalhit/i';

    /** Called once per request before routing. Never breaks a page: any failure is ignored. */
    public static function track(Request $request): void
    {
        if ($request->method !== 'GET' || self::skipped($request->path)) {
            return;
        }
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match(self::BOT, $ua)) {
            return;
        }
        try {
            $vid = self::cookieValue();
            $hash = hash('sha256', $vid);
            $userId = Auth::user()['id'] ?? null;
            Database::query(
                'INSERT INTO visitors (visitor_hash, user_id, first_seen, last_seen) VALUES (?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE last_seen = NOW(), user_id = COALESCE(VALUES(user_id), user_id)',
                [$hash, $userId === null ? null : (int) $userId]
            );
            Database::query('INSERT IGNORE INTO visitor_days (day, visitor_hash) VALUES (CURDATE(), ?)', [$hash]);
        } catch (\Throwable) {
            // counting must never stop a page from loading
        }
    }

    /** The counts shown on the Visitors page. */
    public static function stats(): array
    {
        $days = fn(int $n) => (int) Database::query(
            'SELECT COUNT(DISTINCT visitor_hash) FROM visitor_days WHERE day >= CURDATE() - INTERVAL ' . ($n - 1) . ' DAY'
        )->fetchColumn();
        $online = Database::query(
            'SELECT COUNT(*) AS total, SUM(user_id IS NOT NULL) AS members FROM visitors WHERE last_seen >= NOW() - INTERVAL ' . self::ONLINE_MINUTES . ' MINUTE'
        )->fetch();
        $total = (int) $online['total'];
        $members = (int) ($online['members'] ?? 0);
        return [
            'today' => $days(1),
            'week' => $days(7),
            'month' => $days(30),
            'overall' => (int) Database::query('SELECT COUNT(*) FROM visitors')->fetchColumn(),
            'online' => $total,
            'online_members' => $members,
            'online_guests' => $total - $members,
            'at' => date('H:i:s'),
        ];
    }

    /** The figures shown to shoppers on the storefront badge. */
    public static function live(): array
    {
        try {
            $s = self::stats();
            return ['online' => $s['online'], 'today' => $s['today'], 'week' => $s['week'], 'overall' => $s['overall']];
        } catch (\Throwable) {
            return ['online' => 0, 'today' => 0, 'week' => 0, 'overall' => 0]; // a missing table or a database hiccup must not break the shop's home page
        }
    }

    private static function skipped(string $path): bool
    {
        foreach (self::SKIP_PREFIXES as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) {
                return true;
            }
        }
        return false;
    }

    /** The browser's id from the cookie; a new one is set when there is none (or it is malformed). */
    private static function cookieValue(): string
    {
        $vid = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $vid) === 1) {
            return $vid;
        }
        $vid = bin2hex(random_bytes(16));
        $_COOKIE[self::COOKIE] = $vid;
        if (!headers_sent()) {
            setcookie(self::COOKIE, $vid, [
                'expires' => time() + 365 * 86400,
                'path' => '/',
                'secure' => str_starts_with((string) env('APP_URL', ''), 'https'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return $vid;
    }
}
