<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Setting
{
    public static function get(string $key, string $default = ''): string
    {
        try {
            $v = Database::query('SELECT `value` FROM settings WHERE `key` = ?', [$key])->fetchColumn();
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                return $default; // a new database before its tables exist (the install wizard shows this)
            }
            throw $e;
        }
        return $v === false || $v === null ? $default : (string) $v;
    }

    public static function set(string $key, string $value): void
    {
        Database::query('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, $value]);
    }

    public static function guestCheckoutEnabled(): bool
    {
        return self::get('guest_checkout_enabled', '1') === '1';
    }

    public static function shippingCents(): int
    {
        $v = self::get('shipping_flat', '0.00');
        return \App\Services\PricingService::isMoney($v) ? \App\Services\PricingService::cents($v) : 0;
    }
}
