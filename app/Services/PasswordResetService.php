<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\User;

/** Forgot password: email a one-time link, then set a new password from it. */
final class PasswordResetService
{
    public const MINUTES = 60;
    public const COOLDOWN_SECONDS = 120;

    /** Email a reset link if the address belongs to an active account. The caller never learns which it was. */
    public static function request(string $email, string $ip): void
    {
        $user = User::findByEmail(strtolower(trim($email)));
        if ($user === null || $user['status'] !== 'active') {
            return;
        }
        $recent = (int) Database::query(
            'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL ' . self::COOLDOWN_SECONDS . ' SECOND',
            [(int) $user['id']]
        )->fetchColumn();
        if ($recent > 0) {
            return;
        }
        EmailService::sendPasswordReset((int) $user['id'], (string) $user['email'], (string) $user['name']);
    }

    /** The account a valid, unused, unexpired link belongs to, or null. */
    public static function findByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = Database::query(
            "SELECT u.* FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? AND r.expires_at > NOW() AND u.status = 'active'",
            [hash('sha256', $token)]
        )->fetch();
        return $row ?: null;
    }

    /** Set the new password. The link is used up first, so a second submit of the same link fails. @return array<string,string> errors; empty when done */
    public static function reset(string $token, string $new, string $confirm): array
    {
        $user = self::findByToken($token);
        if ($user === null) {
            return ['form' => 'This reset link is not valid or has expired. Ask for a new one.'];
        }
        $errors = AccountService::newPasswordErrors((string) $user['email'], $new, $confirm);
        if ($errors === [] && password_verify($new, (string) $user['password_hash'])) {
            $errors['password'] = 'Choose a password different from your current one.';
        }
        if ($errors !== []) {
            return $errors;
        }

        $id = (int) $user['id'];
        $used = Database::query('DELETE FROM password_resets WHERE token_hash = ?', [hash('sha256', $token)])->rowCount();
        if ($used !== 1) {
            return ['form' => 'This reset link has already been used. Ask for a new one.'];
        }
        User::setPassword($id, password_hash($new, PASSWORD_DEFAULT));
        Database::query('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$id]);
        Database::query('DELETE FROM password_resets WHERE user_id = ?', [$id]);
        Database::query('DELETE FROM login_attempts WHERE email = ?', [(string) $user['email']]);
        AuditService::log($id, 'auth.password_reset', 'password reset from an emailed link');
        return [];
    }
}
