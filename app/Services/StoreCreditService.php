<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Store credit for signed-in members. A refund can be given as credit instead of cash. Credit is spent at checkout up to
 * the items total. The member's row is locked while credit is spent, so two checkouts cannot use the same credit.
 */
final class StoreCreditService
{
    public static function balance(int $userId): float
    {
        return round((float) Database::query('SELECT COALESCE(SUM(amount), 0) FROM credit_ledger WHERE user_id = ?', [$userId])->fetchColumn(), 2);
    }

    /** The credit that can be used on this order, in cents, locking the member's row. */
    public static function reserve(int $userId, int $itemsCents): int
    {
        Database::query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
        $balanceCents = (int) round(self::balance($userId) * 100);
        return max(0, min($balanceCents, $itemsCents));
    }

    public static function spend(int $orderId, int $userId, int $cents): void
    {
        if ($cents < 1) {
            return;
        }
        Database::query("INSERT INTO credit_ledger (user_id, amount, kind, order_id, note) VALUES (?, ?, 'spend', ?, 'Used at checkout')", [$userId, -$cents / 100, $orderId]);
    }

    /** A refund given as credit. @return string|null error (guests have no account to hold credit). */
    public static function refund(int $orderId, ?int $userId, float $amount, int $actorId): ?string
    {
        if ($userId === null) {
            return 'Store credit needs a member account. Refund this order in cash instead.';
        }
        Database::query("INSERT INTO credit_ledger (user_id, amount, kind, order_id, note) VALUES (?, ?, 'refund', ?, 'Refund given as store credit')", [$userId, round($amount, 2), $orderId]);
        AuditService::log($actorId, 'credit.refund', "order {$orderId}: RM" . number_format($amount, 2) . ' as store credit');
        return null;
    }

    /** A cancelled order that used credit gives it back, once. */
    public static function restore(int $orderId): void
    {
        $row = Database::query("SELECT user_id, -amount AS amt FROM credit_ledger WHERE order_id = ? AND kind = 'spend'", [$orderId])->fetch();
        if (!$row || (int) Database::query("SELECT COUNT(*) FROM credit_ledger WHERE order_id = ? AND kind = 'restore'", [$orderId])->fetchColumn() > 0) {
            return;
        }
        Database::query("INSERT INTO credit_ledger (user_id, amount, kind, order_id, note) VALUES (?, ?, 'restore', ?, 'Order cancelled')", [(int) $row['user_id'], round((float) $row['amt'], 2), $orderId]);
    }
}
