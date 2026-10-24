<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\User;

final class Auth
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_MINUTES = 15;
    /** Failed logins from one IP across ALL emails: stops password spraying that rotates the email. */
    public const MAX_IP_ATTEMPTS = 30;

    /** Where each role lands after login. */
    public const HOME = ['admin' => '/admin', 'staff' => '/staff', 'member' => '/member'];
    public const LOGIN = ['admin' => '/admin/login', 'staff' => '/staff/login', 'member' => '/login'];

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = Session::get('auth_id');
            $user = is_int($id) ? User::find($id) : null;
            // A disabled account loses access immediately, even mid-session.
            if ($user !== null && $user['status'] !== 'active') {
                self::logout();
                $user = null;
            }
            self::$user = $user;
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set('auth_id', (int) $user['id']);
        Database::query('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        self::$user = $user;
        self::$loaded = true;

        if ($user['role'] === 'member') {
            \App\Services\CartService::mergeGuestIntoMember((int) $user['id']);
        }
    }

    public static function logout(): void
    {
        Session::destroy();
        Session::start();
        Session::regenerate();
        self::$user = null;
        self::$loaded = true;
    }

    /**
     * Verify credentials for one portal role. Returns the user, or null on any failure
     * (unknown email, wrong password, wrong portal, disabled) so the caller can't leak which.
     */
    public static function attempt(string $email, string $password, string $role, string $ip): ?array
    {
        $email = strtolower(trim($email));
        $user = User::findByEmail($email);

        // Always run one password_verify so unknown emails cost the same time.
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $ok = password_verify($password, $hash);

        if ($user === null || !$ok || $user['role'] !== $role || $user['status'] !== 'active') {
            Database::query('INSERT INTO login_attempts (email, ip) VALUES (?, ?)', [$email, $ip]);
            return null;
        }

        Database::query('DELETE FROM login_attempts WHERE email = ? AND ip = ?', [$email, $ip]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            User::update((int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        return $user;
    }

    public static function throttled(string $email, string $ip): bool
    {
        $count = Database::query(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND attempted_at > (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)',
            [strtolower(trim($email)), $ip]
        )->fetchColumn();
        if ((int) $count >= self::MAX_ATTEMPTS) {
            return true;
        }
        $byIp = Database::query('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)', [$ip])->fetchColumn();
        return (int) $byIp >= self::MAX_IP_ATTEMPTS;
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }
        if ($user['role'] === 'admin') {
            return true;
        }
        $perms = json_decode((string) $user['permissions'], true);
        return is_array($perms) && in_array($permission, $perms, true);
    }
}
