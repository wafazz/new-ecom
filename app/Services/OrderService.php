<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class OrderService
{
    public const FLOW = [
        'pending' => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['completed', 'returned'],
        'completed' => [],
        'cancelled' => [],
        'returned' => [],
    ];

    public static function find(int $id): ?array
    {
        return Database::query('SELECT * FROM orders WHERE id = ?', [$id])->fetch() ?: null;
    }

    public static function findByNo(string $orderNo): ?array
    {
        return Database::query('SELECT * FROM orders WHERE order_no = ?', [$orderNo])->fetch() ?: null;
    }

    public static function items(int $orderId): array
    {
        return Database::query('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId])->fetchAll();
    }

    /** Order ids from a comma list ("1,2,3"): digits only, unique, at most 50. @return int[] */
    public static function idsFrom(string $csv): array
    {
        $ids = array_map('intval', explode(',', $csv));
        return array_slice(array_values(array_unique(array_filter($ids, fn($i) => $i > 0))), 0, 50);
    }

    /**
     * After the AWB is printed: COD orders go to Shipped; paid non-COD orders go to Shipped and then Completed.
     * Only Processing orders whose courier label was printed are moved.
     *
     * @return array{shipped:int, completed:int, skipped:string[]}
     */
    public static function moveNext(array $ids, int $actorId): array
    {
        $orders = self::findAmong($ids);
        $ships = ShipmentService::activeAmong($ids);
        $out = ['shipped' => 0, 'completed' => 0, 'skipped' => []];
        foreach ($ids as $id) {
            $o = $orders[$id] ?? null;
            if ($o === null) {
                $out['skipped'][] = "Order #{$id} was not found.";
                continue;
            }
            $no = $o['order_no'];
            if ($o['status'] !== 'processing') {
                $out['skipped'][] = "{$no} is {$o['status']}, not processing.";
                continue;
            }
            if (empty($ships[$id]['awb_printed_at'])) {
                $out['skipped'][] = "{$no} has no printed AWB yet.";
                continue;
            }
            $cod = $o['payment_method'] === 'cod';
            if (!$cod && $o['payment_status'] !== 'paid') {
                $out['skipped'][] = "{$no} is not paid yet, so it cannot be completed.";
                continue;
            }
            if (($err = self::transition($id, 'shipped', $actorId)) !== null) {
                $out['skipped'][] = "{$no}: {$err}";
                continue;
            }
            if ($cod) {
                $out['shipped']++;
                continue;
            }
            if (($err = self::transition($id, 'completed', $actorId)) !== null) {
                $out['skipped'][] = "{$no} is now shipped but could not be completed: {$err}";
                continue;
            }
            $out['completed']++;
        }
        AuditService::log($actorId, 'orders.move_next', "shipped {$out['shipped']}, completed {$out['completed']}, skipped " . count($out['skipped']));
        return $out;
    }

    /** Orders by id (from $ids). @return array<int,array> order id => order row */
    public static function findAmong(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::query("SELECT * FROM orders WHERE id IN ({$in})", $ids)->fetchAll() as $o) {
            $out[(int) $o['id']] = $o;
        }
        return $out;
    }

    /** Line items of several orders at once. @return array<int,array> order id => item rows */
    public static function itemsAmong(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::query("SELECT * FROM order_items WHERE order_id IN ({$in}) ORDER BY id", $ids)->fetchAll() as $it) {
            $out[(int) $it['order_id']][] = $it;
        }
        return $out;
    }

    public static function events(int $orderId): array
    {
        return Database::query(
            'SELECT e.*, u.name AS actor FROM order_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.order_id = ? ORDER BY e.id',
            [$orderId]
        )->fetchAll();
    }

    /**
     * Move an order along FLOW inside a transaction. Cancelling returns the stock exactly once
     * (cancelled is terminal). Completing a cash-on-delivery order marks it paid.
     *
     * @return string|null error message, null on success
     */
    public static function transition(int $orderId, string $to, ?int $actorId, ?string $note = null, bool $customerOnlyPending = false): ?string
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $order = Database::query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId])->fetch();
            if (!$order) {
                $pdo->rollBack();
                return 'Order not found.';
            }
            if (!in_array($to, self::FLOW[$order['status']] ?? [], true) || ($customerOnlyPending && $order['status'] !== 'pending')) {
                $pdo->rollBack();
                return "This order is {$order['status']} and can't be changed to {$to}.";
            }

            if ($to === 'cancelled' && Database::query('SELECT COUNT(*) FROM shipments WHERE order_id = ? AND active = 1', [$orderId])->fetchColumn() > 0) {
                $pdo->rollBack();
                return 'Cancel the NinjaVan shipment first, then cancel the order.';
            }
            if (in_array($to, ['cancelled', 'returned'], true)) {
                self::restock($orderId, $actorId, $to === 'returned' ? 'return' : 'cancel');
            }
            $paid = $to === 'completed' && $order['payment_method'] === 'cod' ? ", payment_status = 'paid'" : '';
            Database::query("UPDATE orders SET status = ?{$paid} WHERE id = ?", [$to, $orderId]);
            Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)', [$orderId, $to, $note ?: null, $actorId]);
            if ($to === 'completed') {
                AffiliateService::recordCommission($order, self::items($orderId));
            }
            $pdo->commit();
            return null;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * "Delete" on the order list: the order is not removed. It moves to Cancelled (stock goes back, the history
     * keeps it). Only pending or processing orders with no courier booking.
     *
     * @return string|null error message, null on success
     */
    public static function delete(int $orderId, ?int $actorId): ?string
    {
        $order = self::find($orderId);
        if (!$order) {
            return 'Order not found.';
        }
        if (!in_array($order['status'], ['pending', 'processing'], true)) {
            return "Only pending or processing orders can be removed. This order is {$order['status']}.";
        }
        if (Database::query('SELECT COUNT(*) FROM shipments WHERE order_id = ?', [$orderId])->fetchColumn() > 0) {
            return 'This order has a courier booking. Cancel the NinjaVan shipment first.';
        }
        $err = self::transition($orderId, 'cancelled', $actorId, 'Removed from the order list');
        if ($err !== null) {
            return $err;
        }
        AuditService::log($actorId, 'order.delete', "{$order['order_no']} was {$order['status']}, now cancelled");
        return null;
    }

    /** Fields an admin may correct on a pending or processing order (never before the courier has the order). */
    public const EDITABLE = ['customer_name' => 'name', 'customer_phone' => 'phone', 'ship_line1' => 'address line 1', 'ship_line2' => 'address line 2', 'ship_city' => 'city', 'ship_state' => 'state', 'ship_postcode' => 'postcode'];

    /**
     * Correct the contact and delivery details of an order. $clean is keyed by the order columns of EDITABLE.
     * Logs only the names of the fields that changed, never their values.
     *
     * @return string|null error message, null on success (also when nothing changed)
     */
    public static function updateDetails(int $orderId, array $clean, ?int $actorId): ?string
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $order = Database::query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId])->fetch();
            if (!$order) {
                $pdo->rollBack();
                return 'Order not found.';
            }
            if (!in_array($order['status'], ['pending', 'processing'], true)) {
                $pdo->rollBack();
                return "Only pending or processing orders can be edited. This order is {$order['status']}.";
            }
            if (Database::query('SELECT COUNT(*) FROM shipments WHERE order_id = ?', [$orderId])->fetchColumn() > 0) {
                $pdo->rollBack();
                return 'This order has a courier booking, so its details can no longer be changed.';
            }
            $values = [];
            $fields = [];
            foreach (self::EDITABLE as $column => $label) {
                $new = $clean[$column] ?? null;
                if ((string) ($order[$column] ?? '') !== (string) ($new ?? '')) {
                    $values[$column] = $new;
                    $fields[] = $label;
                }
            }
            if ($values === []) {
                $pdo->rollBack();
                return null;
            }
            $sets = implode(', ', array_map(fn($column) => "{$column} = ?", array_keys($values)));
            Database::query("UPDATE orders SET {$sets} WHERE id = ?", [...array_values($values), $orderId]);
            Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)', [$orderId, 'edited', 'Changed: ' . implode(', ', $fields), $actorId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditService::log($actorId, 'order.edit', "{$order['order_no']}: " . implode(', ', $fields));
        return null;
    }

    /** Put back the stock of every line of an order that is leaving the books (cancel, return or delete). */
    private static function restock(int $orderId, ?int $actorId, string $reason = 'cancel'): void
    {
        foreach (self::items($orderId) as $it) {
            if ($it['product_id'] === null) {
                continue; // the product was deleted for good: nothing to put back
            }
            try {
                StockService::delta((int) $it['product_id'], $it['variant_id'] !== null ? (int) $it['variant_id'] : null, (int) $it['qty'], $reason, $orderId, $actorId);
            } catch (\DomainException) {
                // the variation was removed since the order: its stock no longer exists
            }
        }
    }

    /**
     * A verified online payment arrived. Idempotent (callback + return can both report it). If the order
     * was already cancelled the money still arrived, so it is recorded as paid and flagged for a refund.
     *
     * @return string 'paid' | 'late' (paid after cancellation) | 'already' | 'missing'
     */
    public static function recordPayment(int $orderId, string $note): string
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $order = Database::query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId])->fetch();
            if (!$order) {
                $pdo->rollBack();
                return 'missing';
            }
            if ($order['payment_status'] === 'paid') {
                $pdo->rollBack();
                return 'already';
            }
            Database::query("UPDATE orders SET payment_status = 'paid' WHERE id = ?", [$orderId]);
            $late = $order['status'] === 'cancelled';
            Database::query(
                'INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, NULL)',
                [$orderId, $late ? 'paid_after_cancel' : 'paid', $late ? $note . ' - the order was already cancelled: REFUND NEEDED' : $note]
            );
            $pdo->commit();
            return $late ? 'late' : 'paid';
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Append a history entry without changing state (system actor). */
    public static function note(int $orderId, string $event, string $note): void
    {
        Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, NULL)', [$orderId, $event, mb_substr($note, 0, 255)]);
    }

    public static function markPaid(int $orderId, int $actorId): ?string
    {
        $order = self::find($orderId);
        if (!$order) {
            return 'Order not found.';
        }
        if ($order['status'] === 'cancelled') {
            return 'A cancelled order cannot be marked paid.';
        }
        if ($order['payment_status'] === 'paid') {
            return null;
        }
        Database::query("UPDATE orders SET payment_status = 'paid' WHERE id = ?", [$orderId]);
        Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)', [$orderId, 'paid', 'Marked as paid', $actorId]);
        return null;
    }
}
