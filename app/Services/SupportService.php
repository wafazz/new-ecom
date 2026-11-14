<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Messages about an order, between the customer and the shop. A staff reply emails the customer a pointer back to
 * their order page; the email carries no order secret, so the customer signs in or uses Track order to read it.
 */
final class SupportService
{
    public const MAX_CHARS = 2000;

    /** @return array<int,array{id:int, sender:string, body:string, created_at:string, staff:?string}> oldest first */
    public static function thread(int $orderId): array
    {
        return Database::query(
            'SELECT m.id, m.sender, m.body, m.created_at, u.name staff FROM support_messages m LEFT JOIN users u ON u.id = m.staff_id WHERE m.order_id = ? ORDER BY m.id',
            [$orderId]
        )->fetchAll();
    }

    /** @return string|null error message, or null when posted */
    public static function post(int $orderId, string $sender, string $body, ?int $staffId = null): ?string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_CHARS) {
            return 'Write a message of up to ' . self::MAX_CHARS . ' characters.';
        }
        if (!in_array($sender, ['customer', 'staff'], true)) {
            return 'Unknown sender.';
        }
        Database::query('INSERT INTO support_messages (order_id, sender, staff_id, body) VALUES (?, ?, ?, ?)', [$orderId, $sender, $staffId, $body]);
        if ($sender === 'staff') {
            self::tellCustomer($orderId);
        }
        return null;
    }

    /** Orders whose latest message came from the customer: the ones waiting for the shop. */
    public static function waiting(): array
    {
        return Database::query(
            "SELECT o.id, o.order_no, o.customer_name, o.customer_email, m.created_at last_at, m.body last_body
             FROM orders o JOIN support_messages m ON m.order_id = o.id
             WHERE m.id = (SELECT MAX(x.id) FROM support_messages x WHERE x.order_id = o.id) AND m.sender = 'customer'
             ORDER BY m.id DESC LIMIT 200"
        )->fetchAll();
    }

    private static function tellCustomer(int $orderId): void
    {
        $o = Database::query('SELECT order_no, customer_name, customer_email FROM orders WHERE id = ?', [$orderId])->fetch();
        if (!$o || !EmailService::ready()) {
            return;
        }
        $shop = (string) config('app.name');
        $text = "Hello {$o['customer_name']},\n\nThe {$shop} team has replied about your order {$o['order_no']}.\n\n"
            . "Sign in to your account, or use Track order with your order number and email, to read the reply.";
        MailService::send((string) $o['customer_email'], "Reply about your order {$o['order_no']}", $text, 'support');
    }
}
