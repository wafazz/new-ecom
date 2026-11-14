<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;
use App\Models\User;
use App\Services\Payment\Payments;

/**
 * Subscriptions for signed-in members. Each due date creates one normal order with the member's chosen payment method;
 * the member pays that order. A cycle that is still unpaid pauses the subscription until it is paid, and then it resumes.
 * Run hourly by bin/subscriptions.php.
 */
final class SubscriptionService
{
    public const CADENCES = ['monthly' => 'Monthly', 'fortnightly' => 'Every two weeks'];

    private static function advance(string $date, string $cadence): string
    {
        return $cadence === 'monthly'
            ? date('Y-m-d', strtotime($date . ' +1 month'))
            : date('Y-m-d', strtotime($date . ' +14 days'));
    }

    /** @return string|null error */
    public static function create(int $userId, int $productId, int $addressId, string $cadence, string $method, int $actorId): ?string
    {
        $product = Product::findActive($productId);
        if ($product === null || DigitalService::isDigital($product) || Database::query('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND deleted_at IS NULL', [$productId])->fetchColumn() > 0) {
            return 'Only a live single product can be subscribed to.';
        }
        if (!isset(self::CADENCES[$cadence])) {
            return 'Choose monthly or every two weeks.';
        }
        if (!isset(Payments::checkoutMethods()[$method])) {
            return 'Choose a payment method that is offered at checkout.';
        }
        if (Database::query('SELECT COUNT(*) FROM addresses WHERE id = ? AND user_id = ?', [$addressId, $userId])->fetchColumn() == 0) {
            return 'Choose one of your saved addresses (add one first if you have none).';
        }
        if (Database::query("SELECT COUNT(*) FROM subscriptions WHERE user_id = ? AND product_id = ? AND status <> 'cancelled'", [$userId, $productId])->fetchColumn() > 0) {
            return 'You already have a subscription to this product.';
        }
        Database::query(
            "INSERT INTO subscriptions (user_id, product_id, address_id, qty, cadence, payment_method, next_due) VALUES (?, ?, ?, 1, ?, ?, CURDATE())",
            [$userId, $productId, $addressId, $cadence, $method]
        );
        AuditService::log($actorId, 'subscription.created', "product {$productId}, {$cadence}");
        return null;
    }

    /** Pause, resume or cancel. A member can only change their own; $userId null means an admin. @return string|null */
    public static function change(int $subscriptionId, ?int $userId, string $action): ?string
    {
        $s = Database::query('SELECT * FROM subscriptions WHERE id = ?', [$subscriptionId])->fetch();
        if (!$s || ($userId !== null && (int) $s['user_id'] !== $userId)) {
            return 'That subscription was not found.';
        }
        if ($s['status'] === 'cancelled') {
            return 'This subscription is cancelled.';
        }
        match ($action) {
            'pause' => Database::query("UPDATE subscriptions SET status = 'paused', pause_reason = 'Paused by you' WHERE id = ?", [$subscriptionId]),
            'resume' => Database::query("UPDATE subscriptions SET status = 'active', pause_reason = NULL, next_due = GREATEST(next_due, CURDATE()) WHERE id = ?", [$subscriptionId]),
            'cancel' => Database::query("UPDATE subscriptions SET status = 'cancelled', pause_reason = NULL WHERE id = ?", [$subscriptionId]),
            default => null,
        };
        return null;
    }

    /** Is an earlier cycle's order still unpaid? */
    public static function outstanding(int $subscriptionId): bool
    {
        return (int) Database::query(
            "SELECT COUNT(*) FROM subscription_runs r JOIN orders o ON o.id = r.order_id WHERE r.subscription_id = ? AND o.payment_status = 'unpaid' AND o.status IN ('pending','processing')",
            [$subscriptionId]
        )->fetchColumn() > 0;
    }

    /** The daily/hourly job. Returns counts for the log. */
    public static function runDue(): array
    {
        $out = ['created' => 0, 'paused' => 0, 'resumed' => 0];
        $today = date('Y-m-d');
        foreach (Database::query("SELECT * FROM subscriptions WHERE status IN ('active','paused') AND next_due <= ?", [$today])->fetchAll() as $s) {
            $id = (int) $s['id'];
            if ($s['status'] === 'paused') {
                if (self::outstanding($id) || $s['pause_reason'] === 'Paused by you') {
                    continue;
                }
                Database::query("UPDATE subscriptions SET status = 'active', pause_reason = NULL WHERE id = ?", [$id]);
                $out['resumed']++;
            }
            if (self::outstanding($id)) {
                Database::query("UPDATE subscriptions SET status = 'paused', pause_reason = 'Waiting for the last cycle to be paid' WHERE id = ?", [$id]);
                $out['paused']++;
                continue;
            }
            $due = (string) $s['next_due'];
            if (Database::query('INSERT IGNORE INTO subscription_runs (subscription_id, due_date) VALUES (?, ?)', [$id, $due])->rowCount() === 0) {
                Database::query('UPDATE subscriptions SET next_due = ? WHERE id = ?', [self::advance($due, (string) $s['cadence']), $id]);
                continue;
            }
            $runId = (int) Database::connection()->lastInsertId();
            try {
                $orderId = self::placeOrder($s);
                Database::query('UPDATE subscription_runs SET order_id = ? WHERE id = ?', [$orderId, $runId]);
                Database::query('UPDATE subscriptions SET next_due = ? WHERE id = ?', [self::advance($due, (string) $s['cadence']), $id]);
                $out['created']++;
            } catch (\Throwable $e) {
                Database::query('DELETE FROM subscription_runs WHERE id = ?', [$runId]);
                Database::query("UPDATE subscriptions SET status = 'paused', pause_reason = ? WHERE id = ?", [mb_substr('Could not place the order: ' . $e->getMessage(), 0, 190), $id]);
                $out['paused']++;
            }
        }
        return $out;
    }

    /** Places this cycle's order through checkout, as the member, and emails them the order to pay. @return int order id */
    private static function placeOrder(array $s): int
    {
        $user = User::find((int) $s['user_id']) ?? throw new \RuntimeException('The account no longer exists.');
        $addr = Database::query('SELECT * FROM addresses WHERE id = ? AND user_id = ?', [(int) $s['address_id'], (int) $s['user_id']])->fetch();
        if (!$addr) {
            throw new \RuntimeException('The delivery address was removed.');
        }
        $cartId = null;
        Database::connection()->beginTransaction();
        try {
            Database::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', 'subscription-' . $s['id'] . '-' . $s['next_due'] . '-' . bin2hex(random_bytes(8)))]);
            $cartId = (int) Database::connection()->lastInsertId();
            Database::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty, reserved_until) VALUES (?, ?, NULL, ?, NOW() + INTERVAL 15 MINUTE)', [$cartId, (int) $s['product_id'], (int) $s['qty']]);
            Database::connection()->commit();
        } catch (\Throwable $e) {
            Database::connection()->rollBack();
            throw $e;
        }
        $result = CheckoutService::place($cartId, [
            'name' => $addr['name'], 'email' => $user['email'], 'phone' => $addr['phone'], 'line1' => $addr['line1'],
            'line2' => (string) ($addr['line2'] ?? ''), 'city' => $addr['city'], 'state' => $addr['state'], 'postcode' => $addr['postcode'],
            'notes' => 'Subscription delivery',
        ], (string) $s['payment_method'], (int) $s['user_id']);
        Database::query('DELETE FROM carts WHERE id = ?', [$cartId]);
        $orderNo = (string) $result['order_no'];
        $text = "Hello {$addr['name']},\n\nYour subscription delivery is ready: order {$orderNo}.\n\n"
            . "Please pay it here: " . url('/member/orders/' . $orderNo) . "\n\nThe next delivery is held until this one is paid.";
        MailService::send((string) $user['email'], 'Your subscription order ' . $orderNo, $text, 'subscription');
        return (int) $result['order_id'];
    }

    /** @return array<int,array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return Database::query(
            "SELECT s.*, p.name, p.slug FROM subscriptions s JOIN products p ON p.id = s.product_id WHERE s.user_id = ? ORDER BY s.id DESC",
            [$userId]
        )->fetchAll();
    }
}
