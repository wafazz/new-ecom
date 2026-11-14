<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Returns and refunds. A customer can ask for a return on a completed order that has no digital items and no open
 * request. Staff approve or reject, mark the goods received (stock goes back once), then record the refund.
 * The shop does not pay the refund itself: the refund amount is recorded for the bank transfer or gateway refund.
 */
final class ReturnService
{
    public const OPEN = ['requested', 'approved', 'received'];

    /** The latest request for an order, or null. */
    public static function forOrder(int $orderId): ?array
    {
        return Database::query('SELECT * FROM return_requests WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId])->fetch() ?: null;
    }

    /** @return array{ok:bool, message:string} */
    public static function eligible(array $order): array
    {
        if ($order['status'] !== 'completed') {
            return ['ok' => false, 'message' => 'Returns can be asked for once the order is completed.'];
        }
        $digital = (int) Database::query("SELECT COUNT(*) FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? AND p.product_type = 'digital'", [(int) $order['id']])->fetchColumn();
        if ($digital > 0) {
            return ['ok' => false, 'message' => 'Digital products cannot be returned. Contact the shop if something is wrong.'];
        }
        $open = Database::query('SELECT status FROM return_requests WHERE order_id = ? ORDER BY id DESC LIMIT 1', [(int) $order['id']])->fetchColumn();
        if ($open !== false && in_array($open, self::OPEN, true)) {
            return ['ok' => false, 'message' => 'A return is already open for this order.'];
        }
        if ($open === 'refunded') {
            return ['ok' => false, 'message' => 'This order has already been refunded.'];
        }
        return ['ok' => true, 'message' => ''];
    }

    /** @return string|null error message, or null when the request was saved */
    public static function request(int $orderId, string $reason): ?string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            return 'Tell us the reason in 10 to 500 characters.';
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $order = Database::query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId])->fetch();
            if (!$order) {
                throw new \DomainException('That order does not exist.');
            }
            $check = self::eligible($order);
            if (!$check['ok']) {
                throw new \DomainException($check['message']);
            }
            Database::query('INSERT INTO return_requests (order_id, reason) VALUES (?, ?)', [$orderId, $reason]);
            $pdo->commit();
            return null;
        } catch (\DomainException $e) {
            $pdo->rollBack();
            return $e->getMessage();
        }
    }

    /**
     * Moves a request on. Actions: approve | reject | receive | refund.
     * @return string|null error message, or null when done
     */
    public static function decide(int $requestId, string $action, int $actorId, ?string $note = null, ?string $amount = null, string $method = 'cash'): ?string
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $req = Database::query('SELECT * FROM return_requests WHERE id = ? FOR UPDATE', [$requestId])->fetch();
            if (!$req) {
                throw new \DomainException('That return does not exist.');
            }
            $order = Database::query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [(int) $req['order_id']])->fetch();
            $status = (string) $req['status'];
            $note = $note === null ? null : (mb_substr(trim($note), 0, 500) ?: null);

            switch ($action) {
                case 'approve':
                case 'reject':
                    if ($status !== 'requested') {
                        throw new \DomainException('Only a new request can be approved or rejected.');
                    }
                    if ($action === 'reject' && $note === null) {
                        throw new \DomainException('Say why the return is rejected, so the customer knows.');
                    }
                    Database::query('UPDATE return_requests SET status = ?, staff_note = ?, decided_at = NOW(), decided_by = ? WHERE id = ?',
                        [$action === 'approve' ? 'approved' : 'rejected', $note, $actorId, $requestId]);
                    break;

                case 'receive':
                    if ($status !== 'approved') {
                        throw new \DomainException('Only an approved return can be marked received.');
                    }
                    self::restockOnce($req, $order, $actorId);
                    Database::query("UPDATE return_requests SET status = 'received', staff_note = COALESCE(?, staff_note) WHERE id = ?", [$note, $requestId]);
                    break;

                case 'refund':
                    if ($status !== 'received') {
                        throw new \DomainException('Mark the goods received before recording the refund.');
                    }
                    $value = round((float) str_replace(',', '', (string) $amount), 2);
                    if ($value <= 0 || $value > (float) $order['total']) {
                        throw new \DomainException('The refund must be more than RM0.00 and no more than the order total (RM' . number_format((float) $order['total'], 2) . ').');
                    }
                    if (!in_array($method, ['cash', 'credit'], true)) {
                        throw new \DomainException('Choose cash or store credit.');
                    }
                    if ($method === 'credit' && Modules::enabled('store_credit')) {
                        $creditError = StoreCreditService::refund((int) $order['id'], $order['user_id'] !== null ? (int) $order['user_id'] : null, $value, $actorId);
                        if ($creditError !== null) {
                            throw new \DomainException($creditError);
                        }
                    } elseif ($method === 'credit') {
                        throw new \DomainException('Store credit is switched off. Refund in cash instead.');
                    }
                    Database::query("UPDATE return_requests SET status = 'refunded', refund_amount = ?, refund_method = ?, refunded_at = NOW(), staff_note = COALESCE(?, staff_note) WHERE id = ?",
                        [number_format($value, 2, '.', ''), $method, $note, $requestId]);
                    AuditService::log($actorId, 'return.refunded', "order {$order['order_no']}: RM" . number_format($value, 2, '.', ''));
                    LoyaltyService::reverse((int) $order['id']);
                    break;

                default:
                    throw new \DomainException('Unknown action.');
            }
            $pdo->commit();
            return null;
        } catch (\DomainException $e) {
            $pdo->rollBack();
            return $e->getMessage();
        }
    }

    /** Put physical items back on the shelf, once per return. Digital items have no stock to return. */
    private static function restockOnce(array $req, array $order, int $actorId): void
    {
        if ((int) $req['stock_restored'] === 1) {
            return;
        }
        foreach (Database::query(
            "SELECT oi.product_id, oi.variant_id, oi.qty FROM order_items oi JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ? AND p.product_type <> 'digital'",
            [(int) $order['id']]
        )->fetchAll() as $item) {
            BundleService::adjustLine((int) $item['product_id'], $item['variant_id'] !== null ? (int) $item['variant_id'] : null, (int) $item['qty'], 'return', (int) $order['id'], $actorId);
        }
        Database::query('UPDATE return_requests SET stock_restored = 1 WHERE id = ?', [(int) $req['id']]);
    }

    /** Admin list: newest first, with the order number and customer. */
    public static function listing(string $status = ''): array
    {
        $where = in_array($status, ['requested', 'approved', 'rejected', 'received', 'refunded'], true) ? 'WHERE r.status = ?' : '';
        $args = $where === '' ? [] : [$status];
        return Database::query(
            "SELECT r.*, o.order_no, o.total, o.customer_name, o.customer_email FROM return_requests r JOIN orders o ON o.id = r.order_id {$where} ORDER BY r.id DESC LIMIT 200",
            $args
        )->fetchAll();
    }
}
