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

    /** "Order created" email to the customer, sent now. Never throws. */
    public static function orderCreated(int $orderId, string $trackUrl): void
    {
        try {
            self::orderCreatedSend($orderId, $trackUrl);
        } catch (\Throwable $e) {
            self::report('order email', $e); // an email problem must never break checkout
        }
    }

    /**
     * The order-created email. True when it was sent, or when there was nothing to send (email off, no address).
     * False when the provider refused it, so the queue tries again. Used by the queue worker.
     */
    public static function orderCreatedSend(int $orderId, string $trackUrl): bool
    {
        if (!self::ready() || !EmailSettings::template('order')['on']) {
            return true;
        }
        $order = OrderService::find($orderId);
        if ($order === null || trim((string) $order['customer_email']) === '' || filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL) === false) {
            return true;
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
        $r = MailService::send((string) $order['customer_email'], self::render($tpl['subject'], $vars), self::render($tpl['body'], $vars), 'order', $orderId);
        return $r['ok'];
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
        $seen = UnsubscribeService::set();
        $out = [];
        foreach ($rows as $r) {
            $email = trim((string) $r['email']);
            $key = strtolower($email);
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || isset($seen[$key])) {
                continue; // skipped: not an address, already listed, or unsubscribed
            }
            $seen[$key] = true;
            $out[] = ['email' => $email, 'name' => trim((string) $r['name'])];
        }
        return $out;
    }

    /**
     * Queue an announcement to an audience: one background job per 100 people (bin/worker.php sends them).
     * Records the campaign; its sent and failed counts fill in as the jobs run.
     * @param array $skip lower-cased addresses to leave out (for example, people already sent a voucher)
     * @param int|null $voucherId set for voucher codes: successful sends are recorded so nobody gets the code twice
     * @return array{id:int, recipients:int, queued:int}|string error message
     */
    public static function announce(string $subject, string $body, string $audience, int $actorId, array $skip = [], ?int $voucherId = null, string $kind = 'campaign')
    {
        if (!array_key_exists($audience, self::AUDIENCES)) {
            return 'Choose who should receive it.';
        }
        if (!self::ready()) {
            return 'Turn email on and save the API key and sender first.';
        }
        // $skip: lower-cased addresses to leave out (for example, people already sent this voucher).
        $people = array_values(array_filter(self::recipients($audience), fn($p) => !isset($skip[strtolower($p['email'])])));
        if ($people === []) {
            return 'No one matches that audience yet.';
        }
        Database::query('INSERT INTO email_campaigns (subject, audience, recipients, created_by) VALUES (?, ?, ?, ?)', [mb_substr($subject, 0, 255), $audience, count($people), $actorId]);
        $id = (int) Database::connection()->lastInsertId();
        $shop = (string) config('app.name');
        $chunks = array_chunk($people, MailService::BATCH_SIZE);
        foreach ($chunks as $i => $chunk) {
            $items = array_map(function ($p) use ($body, $shop, $subject) {
                $url = UnsubscribeService::url($p['email']);
                $text = self::render($body, ['name' => $p['name'] !== '' ? $p['name'] : 'Pelanggan', 'shop' => $shop]);
                return [
                    'to' => $p['email'],
                    'subject' => $subject,
                    'text' => $text . "\n\n" . "Anda menerima emel ini kerana pernah berurusan dengan {$shop}. Tidak mahu terima pengumuman lagi? Nyah-langgan di sini:\n{$url}",
                    // Mail clients show an unsubscribe button from these (RFC 8058 one-click).
                    'headers' => ['List-Unsubscribe' => "<{$url}>", 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
                ];
            }, $chunk);
            Queue::push('email.batch', [
                'kind' => $kind,
                'campaign_id' => $id,
                'voucher_id' => $voucherId,
                'idempotency_key' => "campaign-{$id}-{$i}-" . bin2hex(random_bytes(6)),
                'items' => $items,
                'round' => 0,
            ]);
        }
        AuditService::log($actorId, 'email.announce', "campaign {$id} to {$audience}: " . count($people) . ' people queued');
        return ['id' => $id, 'recipients' => count($people), 'queued' => count($chunks)];
    }

    /**
     * Queue job: send one batch of a campaign. Voucher batches skip anyone who already has the code (checked now,
     * since earlier jobs may have sent it). People whose email failed go into a new job, up to three more rounds.
     * @return bool always true: the job is finished, and any failures are queued again or counted
     */
    public static function deliverBatch(array $p): bool
    {
        $kind = (string) ($p['kind'] ?? 'campaign');
        $campaign = (int) ($p['campaign_id'] ?? 0);
        $voucherId = isset($p['voucher_id']) ? (int) $p['voucher_id'] : null;
        $round = (int) ($p['round'] ?? 0);
        $items = (array) ($p['items'] ?? []);
        if ($voucherId !== null) {
            $already = VoucherService::emailedAddresses($voucherId);
            $items = array_values(array_filter($items, fn($it) => !isset($already[strtolower((string) $it['to'])])));
        }
        if ($items === []) {
            return true;
        }

        $results = MailService::batch($items, $kind, (string) $p['idempotency_key']);
        usleep(150000); // stay well under Resend's 10 requests per second when many batches run back to back
        $failed = [];
        $sent = 0;
        foreach ($items as $i => $it) {
            if ($results[$i]['ok']) {
                $sent++;
                if ($voucherId !== null) {
                    Database::query('INSERT IGNORE INTO voucher_sends (voucher_id, email) VALUES (?, ?)', [$voucherId, strtolower((string) $it['to'])]);
                }
            } else {
                $failed[] = $it;
            }
        }
        if ($campaign > 0 && $sent > 0) {
            Database::query('UPDATE email_campaigns SET sent = sent + ? WHERE id = ?', [$sent, $campaign]);
        }
        if ($failed !== []) {
            if ($round < 3) {
                Queue::push('email.batch', [
                    'kind' => $kind,
                    'campaign_id' => $campaign,
                    'voucher_id' => $voucherId,
                    'idempotency_key' => 'campaign-' . $campaign . '-retry-' . bin2hex(random_bytes(6)),
                    'items' => $failed,
                    'round' => $round + 1,
                ], 300); // five minutes later
            } elseif ($campaign > 0) {
                Database::query('UPDATE email_campaigns SET failed = failed + ? WHERE id = ?', [count($failed), $campaign]);
            }
        }
        return true;
    }

    /** Errors go to the app log, never to the customer. */
    private static function report(string $what, \Throwable $e): void
    {
        @file_put_contents(BASE_PATH . '/storage/logs/app.log', '[' . date('c') . "] {$what}: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
}
