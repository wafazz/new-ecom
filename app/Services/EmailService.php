<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** The emails the shop sends: confirm an address, order created, and announcements to members or customers. */
final class EmailService
{
    public const VERIFY_HOURS = 48;
    public const RESEND_SECONDS = 120;
    public const AUDIENCES = ['members' => 'Members', 'customers' => 'Customers (incl. guests)', 'everyone' => 'Everyone (members and customers)'];

    public static function ready(): bool
    {
        return EmailSettings::enabled() && EmailSettings::apiKey() !== null && EmailSettings::fromEmail() !== '';
    }

    /** Replace {placeholders}; unknown ones stay as they are. */
    public static function render(string $template, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = (string) $v;
        }
        return strtr($template, $map);
    }

    /** Create a confirmation link for a member and email it. @return string 'sent'|'off'|'failed' */
    public static function sendVerification(int $userId, string $email, string $name): string
    {
        if (!self::ready() || !EmailSettings::template('verify')['on']) {
            return 'off';
        }
        $token = bin2hex(random_bytes(32));
        Database::query('DELETE FROM email_verifications WHERE user_id = ?', [$userId]);
        Database::query('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL ' . self::VERIFY_HOURS . ' HOUR)', [$userId, hash('sha256', $token)]);
        $tpl = EmailSettings::template('verify');
        $vars = ['name' => $name, 'verify_url' => url('/verify-email/' . $token), 'shop' => (string) config('app.name')];
        $r = MailService::send($email, self::render($tpl['subject'], $vars), self::render($tpl['body'], $vars), 'verify');
        return $r['ok'] ? 'sent' : 'failed';
    }

    /** Resend the link, at most once every RESEND_SECONDS. @return string 'sent'|'wait'|'off'|'failed'|'verified' */
    public static function resendVerification(int $userId): string
    {
        $user = Database::query('SELECT name, email, email_verified_at FROM users WHERE id = ?', [$userId])->fetch();
        if (!$user) {
            return 'off';
        }
        if ($user['email_verified_at'] !== null) {
            return 'verified';
        }
        $recent = (int) Database::query(
            'SELECT COUNT(*) FROM email_verifications WHERE user_id = ? AND created_at > NOW() - INTERVAL ' . self::RESEND_SECONDS . ' SECOND',
            [$userId]
        )->fetchColumn();
        if ($recent > 0) {
            return 'wait';
        }
        return self::sendVerification($userId, (string) $user['email'], (string) $user['name']);
    }

    /** Create a one-time reset link for an account and email it. @return string 'sent'|'off'|'failed' */
    public static function sendPasswordReset(int $userId, string $email, string $name): string
    {
        if (!self::ready() || !EmailSettings::template('reset')['on']) {
            return 'off';
        }
        $token = bin2hex(random_bytes(32));
        Database::query('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
        Database::query('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL ' . PasswordResetService::MINUTES . ' MINUTE)', [$userId, hash('sha256', $token)]);
        $tpl = EmailSettings::template('reset');
        $vars = ['name' => $name, 'reset_url' => url('/reset-password/' . $token), 'shop' => (string) config('app.name')];
        $r = MailService::send($email, self::render($tpl['subject'], $vars), self::render($tpl['body'], $vars), 'reset');
        return $r['ok'] ? 'sent' : 'failed';
    }

    /** The user id for a valid, unexpired link (and marks the address confirmed), or null. */
    public static function confirm(string $token): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = Database::query('SELECT id, user_id FROM email_verifications WHERE token_hash = ? AND expires_at > NOW()', [hash('sha256', $token)])->fetch();
        if (!$row) {
            return null;
        }
        Database::query('UPDATE users SET email_verified_at = NOW() WHERE id = ?', [(int) $row['user_id']]);
        Database::query('DELETE FROM email_verifications WHERE user_id = ?', [(int) $row['user_id']]);
        AuditService::log((int) $row['user_id'], 'auth.email_verified', 'email address confirmed');
        return (int) $row['user_id'];
    }

    /** "Order created" email to the customer. Never throws. */
    public static function orderCreated(int $orderId, string $trackUrl): void
    {
        try {
            if (!self::ready() || !EmailSettings::template('order')['on']) {
                return;
            }
            $order = OrderService::find($orderId);
            if ($order === null || trim((string) $order['customer_email']) === '' || filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL) === false) {
                return;
            }
            $tpl = EmailSettings::template('order');
            $vars = [
                'name' => (string) $order['customer_name'],
                'order_no' => (string) $order['order_no'],
                'total' => number_format((float) $order['total'], 2, '.', ''),
                'items' => WhatsAppService::orderedItems($orderId),
                'payment' => \App\Services\Payment\Payments::label((string) $order['payment_method']),
                'track_url' => $trackUrl,
                'shop' => (string) config('app.name'),
            ];
            MailService::send((string) $order['customer_email'], self::render($tpl['subject'], $vars), self::render($tpl['body'], $vars), 'order', $orderId);
        } catch (\Throwable $e) {
            self::report('order email', $e); // an email problem must never break checkout
        }
    }

    /**
     * Unique people for an audience: members (active accounts) and/or customers (anyone who ordered, guests included).
     * @return array<int,array{email:string, name:string}>
     */
    public static function recipients(string $audience): array
    {
        $rows = [];
        if (in_array($audience, ['members', 'everyone'], true)) {
            $rows = array_merge($rows, Database::query("SELECT email, name FROM users WHERE role = 'member' AND status = 'active' AND email IS NOT NULL AND email <> ''")->fetchAll());
        }
        if (in_array($audience, ['customers', 'everyone'], true)) {
            $rows = array_merge($rows, Database::query("SELECT customer_email AS email, customer_name AS name FROM orders WHERE customer_email IS NOT NULL AND customer_email <> '' ORDER BY id DESC")->fetchAll());
        }
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $email = trim((string) $r['email']);
            $key = strtolower($email);
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['email' => $email, 'name' => trim((string) $r['name'])];
        }
        return $out;
    }

    /**
     * Send an announcement to an audience in batches of 100. Records the campaign and its counts.
     * @return array{id:int, recipients:int, sent:int, failed:int}|string error message
     */
    public static function announce(string $subject, string $body, string $audience, int $actorId)
    {
        if (!array_key_exists($audience, self::AUDIENCES)) {
            return 'Choose who should receive it.';
        }
        if (!self::ready()) {
            return 'Turn email on and save the API key and sender first.';
        }
        $people = self::recipients($audience);
        if ($people === []) {
            return 'No one matches that audience yet.';
        }
        Database::query('INSERT INTO email_campaigns (subject, audience, recipients, created_by) VALUES (?, ?, ?, ?)', [mb_substr($subject, 0, 255), $audience, count($people), $actorId]);
        $id = (int) Database::connection()->lastInsertId();
        $sent = 0;
        $failed = 0;
        $shop = (string) config('app.name');
        foreach (array_chunk($people, MailService::BATCH_SIZE) as $i => $chunk) {
            $items = array_map(fn($p) => [
                'to' => $p['email'],
                'subject' => $subject,
                'text' => self::render($body, ['name' => $p['name'] !== '' ? $p['name'] : 'Pelanggan', 'shop' => $shop]),
            ], $chunk);
            foreach (MailService::batch($items, 'campaign', "campaign-{$id}-{$i}-" . bin2hex(random_bytes(6))) as $r) {
                $r['ok'] ? $sent++ : $failed++;
            }
            if (count($people) > MailService::BATCH_SIZE) {
                usleep(150000); // stay well under Resend's 10 requests per second
            }
        }
        Database::query('UPDATE email_campaigns SET sent = ?, failed = ? WHERE id = ?', [$sent, $failed, $id]);
        AuditService::log($actorId, 'email.announce', "campaign {$id} to {$audience}: {$sent} sent, {$failed} failed");
        return ['id' => $id, 'recipients' => count($people), 'sent' => $sent, 'failed' => $failed];
    }

    /** Errors go to the app log, never to the customer. */
    private static function report(string $what, \Throwable $e): void
    {
        @file_put_contents(BASE_PATH . '/storage/logs/app.log', '[' . date('c') . "] {$what}: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
}
