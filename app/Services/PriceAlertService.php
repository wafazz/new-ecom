<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;

/**
 * "Tell me when it drops to RM X". Checked against the price a shopper would pay now (sale price included). Every
 * price change queues one check per product; each waiting address is emailed once, when the price is at or below its target.
 */
final class PriceAlertService
{
    /** @return string 'saved' | 'at_or_below' | 'invalid' | 'missing' */
    public static function store(int $productId, string $target, string $email, ?int $userId): string
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || !preg_match('/^\d{1,5}(\.\d{1,2})?$/', trim($target)) || (float) $target < 0.01) {
            return 'invalid';
        }
        $product = Product::findActive($productId);
        if ($product === null || DigitalService::isDigital($product)) {
            return 'missing';
        }
        $now = (float) PricingService::effective($product)['price'];
        if ((float) $target >= $now) {
            return 'at_or_below';
        }
        Database::query(
            'INSERT INTO price_alerts (product_id, target_price, email, user_id) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE target_price = VALUES(target_price), user_id = COALESCE(VALUES(user_id), user_id), notified_at = NULL',
            [$productId, number_format((float) $target, 2, '.', ''), $email, $userId]
        );
        return 'saved';
    }

    /** Queue job: email each waiting address whose target the current price has reached. */
    public static function notify(int $productId): bool
    {
        $product = Product::findActive($productId);
        if ($product === null) {
            return true;
        }
        $now = (float) PricingService::effective($product)['price'];
        $unsub = UnsubscribeService::set();
        $rows = Database::query(
            'SELECT id, email, target_price FROM price_alerts WHERE product_id = ? AND notified_at IS NULL AND target_price >= ? LIMIT 500',
            [$productId, number_format($now, 2, '.', '')]
        )->fetchAll();
        $shop = (string) config('app.name');
        $link = url('/products/' . $product['slug']);
        foreach ($rows as $row) {
            if (isset($unsub[strtolower((string) $row['email'])])) {
                Database::query('UPDATE price_alerts SET notified_at = NOW() WHERE id = ?', [(int) $row['id']]);
                continue;
            }
            $text = "{$product['name']} is now RM" . number_format($now, 2) . " at {$shop}, at or below your RM" . number_format((float) $row['target_price'], 2) . " target.\n\nSee it here: {$link}\n\nYou asked to hear about this, so this is the only email about it.";
            $r = MailService::send((string) $row['email'], "{$product['name']} price drop", $text, 'price_alert');
            if ($r['ok']) {
                Database::query('UPDATE price_alerts SET notified_at = NOW() WHERE id = ?', [(int) $row['id']]);
            }
        }
        return true;
    }
}
