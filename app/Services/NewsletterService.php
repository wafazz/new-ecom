<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Newsletter sign-up with double opt-in. A sign-up is only added after the address confirms from the emailed link.
 * Every answer to a sign-up reads the same whether or not the address is already on the list, so the form cannot
 * be used to find out who has an account.
 */
final class NewsletterService
{
    public const CONFIRM_DAYS = 7;

    /** @return string 'sent' (confirmation email on its way) | 'active' (already subscribed) | 'off' (email not set up) | 'failed' | 'invalid' */
    public static function subscribe(string $email): string
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'invalid';
        }
        $row = Database::query('SELECT status FROM newsletter_subscribers WHERE email = ?', [$email])->fetch();
        if ($row && $row['status'] === 'active') {
            return 'active';
        }
        if (!EmailService::ready()) {
            return 'off';
        }

        $token = bin2hex(random_bytes(32));
        Database::query(
            "INSERT INTO newsletter_subscribers (email, status, token_hash, token_expires) VALUES (?, 'pending', ?, NOW() + INTERVAL " . self::CONFIRM_DAYS . " DAY)
             ON DUPLICATE KEY UPDATE status = IF(status = 'unsubscribed', 'pending', status), token_hash = VALUES(token_hash), token_expires = VALUES(token_expires)",
            [$email, hash('sha256', $token)]
        );
        $shop = (string) config('app.name');
        $text = "Thanks for signing up to the {$shop} newsletter.\n\nConfirm your address to start receiving news and offers:\n"
            . url('/newsletter/confirm/' . $token)
            . "\n\nIf you did not sign up, ignore this email and nothing will be added.";
        $r = MailService::send($email, "Confirm your {$shop} newsletter sign-up", $text, 'newsletter');
        return $r['ok'] ? 'sent' : 'failed';
    }

    /** The confirmed address for a valid, unexpired link, or null. */
    public static function confirm(string $token): ?string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = Database::query(
            "SELECT email FROM newsletter_subscribers WHERE token_hash = ? AND status = 'pending' AND token_expires > NOW()",
            [hash('sha256', $token)]
        )->fetch();
        if (!$row) {
            return null;
        }
        Database::query("UPDATE newsletter_subscribers SET status = 'active', confirmed_at = NOW(), token_hash = NULL, token_expires = NULL WHERE email = ?", [$row['email']]);
        return (string) $row['email'];
    }

    /** Called when someone uses an unsubscribe link: they leave the newsletter too. */
    public static function unsubscribe(string $email): void
    {
        Database::query("UPDATE newsletter_subscribers SET status = 'unsubscribed', token_hash = NULL, token_expires = NULL WHERE email = ?", [strtolower(trim($email))]);
    }

    /** @return array{active:int, pending:int, unsubscribed:int} */
    public static function counts(): array
    {
        $out = ['active' => 0, 'pending' => 0, 'unsubscribed' => 0];
        foreach (Database::query('SELECT status, COUNT(*) c FROM newsletter_subscribers GROUP BY status')->fetchAll() as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    /** Active subscribers only, newest first: the list that may be emailed. */
    public static function activeRows(): array
    {
        return Database::query("SELECT email, confirmed_at FROM newsletter_subscribers WHERE status = 'active' ORDER BY confirmed_at DESC")->fetchAll();
    }
}
