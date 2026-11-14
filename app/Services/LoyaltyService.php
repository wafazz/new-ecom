<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Loyalty points for signed-in members. Earn: 1 point per RM of the order's value after discounts (postage excluded),
 * credited when the order completes. Spend: at checkout, up to half of the items' value. Reverse: when a refund is
 * recorded, the points earned on that order are taken back. Points are never spent twice on one order.
 */
final class LoyaltyService
{
    public const POINTS_PER_RM = 1;
    public const MAX_SHARE = 0.5;

    public static function balance(int $userId): int
    {
        return (int) Database::query('SELECT COALESCE(SUM(points), 0) FROM loyalty_ledger WHERE user_id = ?', [$userId])->fetchColumn();
    }

    /**
     * How many points can be spent on this checkout, and their value in cents (1 point = 1 cent). Locks the member's row,
     * so two checkouts cannot spend the same balance. Call inside a transaction when the points are actually spent.
     * @return array{points:int, cents:int}
     */
    public static function discountFor(int $userId, int $itemsCents, int $requested): array
    {
        Database::query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
        $cap = (int) floor(max(0, $itemsCents) * self::MAX_SHARE);
        $points = max(0, min($requested, self::balance($userId), $cap));
        return ['points' => $points, 'cents' => $points];
    }

    /** Called when an order completes. One earn per order. */
    public static function earn(int $orderId): void
    {
        $o = Database::query('SELECT user_id, subtotal, discount_total FROM orders WHERE id = ?', [$orderId])->fetch();
        if (!$o || $o['user_id'] === null) {
            return;
        }
        $rm = floor(((float) $o['subtotal'] - (float) $o['discount_total']));
        $points = (int) $rm * self::POINTS_PER_RM;
        if ($points < 1) {
            return;
        }
        Database::query("INSERT IGNORE INTO loyalty_ledger (user_id, points, kind, order_id, note) VALUES (?, ?, 'earn', ?, 'Order completed')", [(int) $o['user_id'], $points, $orderId]);
    }

    /** Points spent on an order at checkout. */
    public static function spend(int $orderId, int $userId, int $points): void
    {
        if ($points < 1) {
            return;
        }
        Database::query("INSERT IGNORE INTO loyalty_ledger (user_id, points, kind, order_id, note) VALUES (?, ?, 'spend', ?, 'Used at checkout')", [$userId, -$points, $orderId]);
    }

    /** A refund was recorded: take back the points this order earned. */
    public static function reverse(int $orderId): void
    {
        $o = Database::query('SELECT user_id FROM orders WHERE id = ?', [$orderId])->fetch();
        if (!$o || $o['user_id'] === null) {
            return;
        }
        $earned = (int) Database::query("SELECT COALESCE(SUM(points), 0) FROM loyalty_ledger WHERE order_id = ? AND kind = 'earn'", [$orderId])->fetchColumn();
        // Points already spent on later orders cannot be taken back: the balance never goes below zero from a refund.
        $take = min($earned, max(0, self::balance((int) $o['user_id'])));
        if ($take > 0) {
            Database::query("INSERT IGNORE INTO loyalty_ledger (user_id, points, kind, order_id, note) VALUES (?, ?, 'reverse', ?, 'Order refunded')", [(int) $o['user_id'], -$take, $orderId]);
        }
    }
}
