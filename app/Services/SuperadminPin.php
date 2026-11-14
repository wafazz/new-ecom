<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * The 6-digit PIN a superadmin enters to switch a module on or off. Until a superadmin sets their own, the default PIN
 * applies and the Modules page asks them to change it. Five wrong tries lock the PIN for 15 minutes.
 */
final class SuperadminPin
{
    public const DEFAULT_PIN = '123456';
    public const MAX_FAILS = 5;
    public const LOCK_MINUTES = 15;

    public static function isCustom(int $userId): bool
    {
        return (string) Database::query('SELECT COALESCE(pin_hash, \'\') FROM users WHERE id = ?', [$userId])->fetchColumn() !== '';
    }

    /** @return array{ok:bool, error:?string} error is 'wrong', 'locked' or null */
    public static function verify(int $userId, string $pin): array
    {
        $u = Database::query('SELECT pin_hash, pin_failed, pin_locked_until FROM users WHERE id = ?', [$userId])->fetch();
        if (!$u) {
            return ['ok' => false, 'error' => 'wrong'];
        }
        if ($u['pin_locked_until'] !== null && (string) $u['pin_locked_until'] > date('Y-m-d H:i:s')) {
            return ['ok' => false, 'error' => 'locked'];
        }
        $hash = (string) ($u['pin_hash'] ?? '');
        $good = preg_match('/^\d{6}$/', $pin) === 1
            && ($hash === '' ? hash_equals(self::DEFAULT_PIN, $pin) : password_verify($pin, $hash));
        if ($good) {
            Database::query('UPDATE users SET pin_failed = 0, pin_locked_until = NULL WHERE id = ?', [$userId]);
            return ['ok' => true, 'error' => null];
        }
        $fails = (int) $u['pin_failed'] + 1;
        if ($fails >= self::MAX_FAILS) {
            Database::query('UPDATE users SET pin_failed = 0, pin_locked_until = NOW() + INTERVAL ' . self::LOCK_MINUTES . ' MINUTE WHERE id = ?', [$userId]);
            AuditService::log($userId, 'pin.locked', 'too many wrong PINs');
            return ['ok' => false, 'error' => 'locked'];
        }
        Database::query('UPDATE users SET pin_failed = ? WHERE id = ?', [$fails, $userId]);
        return ['ok' => false, 'error' => 'wrong'];
    }

    /** A new PIN must be 6 digits and must not be the default. @return string|null error */
    public static function validateNew(string $pin, string $confirm): ?string
    {
        if (preg_match('/^\d{6}$/', $pin) !== 1) {
            return 'The PIN is 6 digits.';
        }
        if ($pin !== $confirm) {
            return 'The two PINs do not match.';
        }
        if (hash_equals(self::DEFAULT_PIN, $pin)) {
            return 'Choose a PIN other than the default.';
        }
        return null;
    }

    /** Changes the PIN after checking the current one. @return string|null error */
    public static function change(int $userId, string $current, string $new, string $confirm): ?string
    {
        $check = self::verify($userId, $current);
        if (!$check['ok']) {
            return $check['error'] === 'locked' ? 'Too many wrong PINs. Try again in 15 minutes.' : 'The current PIN is not right.';
        }
        if (($error = self::validateNew($new, $confirm)) !== null) {
            return $error;
        }
        Database::query('UPDATE users SET pin_hash = ?, pin_failed = 0, pin_locked_until = NULL WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $userId]);
        AuditService::log($userId, 'pin.changed', 'superadmin PIN changed (value not logged)');
        return null;
    }
}
