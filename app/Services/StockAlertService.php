<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;

/**
 * "Tell me when it's back in stock". A shopper signs up on a sold-out product or option. When stock rises from zero,
 * StockService queues one job per product and option, and each waiting address gets one email.
 */
final class StockAlertService
{
    /** @return string 'saved' | 'in_stock' | 'invalid' | 'off' | 'failed' */
    public static function store(int $productId, int $variantKey, string $email, ?int $userId): string
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'invalid';
        }
        $product = Product::findActive($productId);
        if ($product === null || \App\Services\DigitalService::isDigital($product)) {
            return 'invalid'; // digital products are not restocked by count
        }
        if (self::stockOf($productId, $variantKey) > 0) {
            return 'in_stock';
        }
        Database::query(
            'INSERT INTO stock_alerts (product_id, variant_key, email, user_id) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE notified_at = NULL, user_id = COALESCE(VALUES(user_id), user_id)',
            [$productId, $variantKey, $email, $userId]
        );
        return 'saved';
    }

    /** Units now on the shelf for a product, or one of its options when $variantKey > 0. */
    public static function stockOf(int $productId, int $variantKey): int
    {
        if ($variantKey > 0) {
            return (int) Database::query('SELECT stock FROM product_variants WHERE id = ? AND product_id = ? AND deleted_at IS NULL', [$variantKey, $productId])->fetchColumn();
        }
        return (int) Database::query('SELECT stock FROM products WHERE id = ?', [$productId])->fetchColumn();
    }

    /** Whether any alert is still waiting for this product or option (used to avoid queueing empty jobs). */
    public static function waiting(int $productId, int $variantKey): bool
    {
        return (int) Database::query('SELECT COUNT(*) FROM stock_alerts WHERE product_id = ? AND variant_key = ? AND notified_at IS NULL', [$productId, $variantKey])->fetchColumn() > 0;
    }

    /** Queue job: email everyone waiting. Each address is marked notified only after its email is accepted. */
    public static function notify(int $productId, int $variantKey): bool
    {
        if (self::stockOf($productId, $variantKey) <= 0) {
            return true; // sold out again before the job ran: keep the alerts for the next restock
        }
        $product = Product::findActive($productId);
        if ($product === null) {
            return true;
        }
        $unsub = UnsubscribeService::set();
        $name = (string) $product['name'];
        $variant = $variantKey > 0 ? VariantService::label($variantKey) : '';
        $what = $variant !== '' ? "{$name} ({$variant})" : $name;
        $link = url('/products/' . $product['slug']);
        $shop = (string) config('app.name');

        $rows = Database::query('SELECT id, email FROM stock_alerts WHERE product_id = ? AND variant_key = ? AND notified_at IS NULL LIMIT 500', [$productId, $variantKey])->fetchAll();
        foreach ($rows as $row) {
            if (isset($unsub[strtolower((string) $row['email'])])) {
                Database::query('UPDATE stock_alerts SET notified_at = NOW() WHERE id = ?', [(int) $row['id']]);
                continue;
            }
            $text = "{$what} is back in stock at {$shop}.\n\nSee it here: {$link}\n\nYou asked to hear about this, so this is the only email about it.";
            $r = MailService::send((string) $row['email'], "{$what} is back in stock", $text, 'stock_alert');
            if ($r['ok']) {
                Database::query('UPDATE stock_alerts SET notified_at = NOW() WHERE id = ?', [(int) $row['id']]);
            }
        }
        return true;
    }
}
