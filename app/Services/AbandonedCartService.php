<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Reminders for signed-in members who leave items in their cart. Holds lapse, so the cart is snapshotted while its items
 * are held. A reminder goes out once the snapshot has been idle for the delay, unless the member has ordered since.
 * The reminder link restores the snapshot into the cart, at current prices, for items still in stock.
 */
final class AbandonedCartService
{
    public const DELAY_SECONDS = 7200; // 2 hours

    /** Saves the held items of each member cart whose contents changed. @return int carts saved */
    public static function snapshot(): int
    {
        $saved = 0;
        $carts = Database::query(
            "SELECT c.id cart_id, c.user_id FROM carts c WHERE c.user_id IS NOT NULL
             AND EXISTS (SELECT 1 FROM cart_items ci WHERE ci.cart_id = c.id AND ci.reserved_until > NOW())"
        )->fetchAll();
        foreach ($carts as $c) {
            $items = Database::query(
                'SELECT product_id, variant_id, qty FROM cart_items WHERE cart_id = ? AND reserved_until > NOW() ORDER BY product_id, variant_id',
                [(int) $c['cart_id']]
            )->fetchAll();
            $json = json_encode(array_map(fn($i) => ['product_id' => (int) $i['product_id'], 'variant_id' => $i['variant_id'] === null ? null : (int) $i['variant_id'], 'qty' => (int) $i['qty']], $items));
            $existing = Database::query('SELECT items_json FROM abandoned_carts WHERE cart_id = ?', [(int) $c['cart_id']])->fetchColumn();
            if ($existing === $json) {
                continue; // same items as before: the idle clock keeps running
            }
            Database::query(
                'INSERT INTO abandoned_carts (cart_id, user_id, items_json, last_seen_at, reminded_at, restore_hash) VALUES (?, ?, ?, NOW(), NULL, NULL)
                 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), items_json = VALUES(items_json), last_seen_at = NOW(), reminded_at = NULL, restore_hash = NULL',
                [(int) $c['cart_id'], (int) $c['user_id'], $json]
            );
            $saved++;
        }
        return $saved;
    }

    /** Idle snapshots due a reminder: not reminded yet, idle for the delay, no order placed since, active account. */
    public static function due(int $delaySeconds = self::DELAY_SECONDS): array
    {
        return Database::query(
            "SELECT a.cart_id, a.user_id, a.items_json, u.name, u.email FROM abandoned_carts a JOIN users u ON u.id = a.user_id
             WHERE a.reminded_at IS NULL AND u.status = 'active' AND a.last_seen_at <= (NOW() - INTERVAL " . (int) $delaySeconds . " SECOND)
               AND a.items_json <> '[]'
               AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = a.user_id AND o.created_at >= a.last_seen_at)
             ORDER BY a.last_seen_at LIMIT 200"
        )->fetchAll();
    }

    /** Sends the reminders that are due. An address that unsubscribed is skipped. @return int reminders sent */
    public static function remind(int $delaySeconds = self::DELAY_SECONDS): int
    {
        $unsub = UnsubscribeService::set();
        $sent = 0;
        foreach (self::due($delaySeconds) as $row) {
            if (isset($unsub[strtolower((string) $row['email'])])) {
                Database::query('UPDATE abandoned_carts SET reminded_at = NOW() WHERE cart_id = ?', [(int) $row['cart_id']]);
                continue;
            }
            $token = bin2hex(random_bytes(32));
            Database::query('UPDATE abandoned_carts SET restore_hash = ? WHERE cart_id = ?', [hash('sha256', $token), (int) $row['cart_id']]);
            $shop = (string) config('app.name');
            $text = "Hello {$row['name']},\n\nYou left items in your basket at {$shop}. Items are only held for a short time, so they were released.\n\n"
                . "Sign in, then open this link to add them back. Prices and stock are checked again when you add them:\n"
                . url('/cart/restore/' . $token) . "\n\nIf you have already bought these items, you can ignore this email.";
            $r = MailService::send((string) $row['email'], "Your items at {$shop}", $text, 'abandoned_cart');
            if ($r['ok']) {
                Database::query('UPDATE abandoned_carts SET reminded_at = NOW() WHERE cart_id = ?', [(int) $row['cart_id']]);
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * Adds a snapshot back to the member's cart. The link works once and only for the member it was sent to.
     * @return array{added:int, failed:int}|null null when the link is not valid
     */
    public static function restore(int $userId, string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = Database::query('SELECT cart_id, items_json FROM abandoned_carts WHERE user_id = ? AND restore_hash = ?', [$userId, hash('sha256', $token)])->fetch();
        if (!$row) {
            return null;
        }
        Database::query('UPDATE abandoned_carts SET restore_hash = NULL WHERE cart_id = ?', [(int) $row['cart_id']]);
        $added = 0;
        $failed = 0;
        foreach (json_decode((string) $row['items_json'], true) ?: [] as $i) {
            $err = CartService::add((int) $i['product_id'], $i['variant_id'] === null ? null : (int) $i['variant_id'], (int) $i['qty']);
            $err === null ? $added++ : $failed++;
        }
        return ['added' => $added, 'failed' => $failed];
    }
}
