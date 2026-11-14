<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** What each module costs and how often it is billed. Set by the superadmin. An amount of 0 means the module is free. */
final class ModulePricing
{
    public const PERIODS = ['one_time' => 'One-time', 'monthly' => 'Monthly', 'yearly' => 'Yearly'];
    public const MAX_AMOUNT = 99999.99;

    /** @return array{period:string, amount:float} */
    public static function price(string $key): array
    {
        $r = Database::query('SELECT period, amount FROM module_prices WHERE module_key = ?', [$key])->fetch();
        return $r ? ['period' => (string) $r['period'], 'amount' => (float) $r['amount']] : ['period' => 'monthly', 'amount' => 0.0];
    }

    public static function isFree(string $key): bool
    {
        return self::price($key)['amount'] <= 0;
    }

    /** @return string|null error message, or null when valid */
    public static function validate(string $period, string $amount): ?string
    {
        if (!isset(self::PERIODS[$period])) {
            return 'Choose one-time, monthly or yearly.';
        }
        if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $amount) || (float) $amount > self::MAX_AMOUNT) {
            return 'Enter an amount from 0 to 99999.99 with at most 2 decimals.';
        }
        return null;
    }

    public static function set(string $key, string $period, string $amount, int $actorId): ?string
    {
        if (!isset(Modules::LIST[$key])) {
            return 'Unknown module.';
        }
        if (($error = self::validate($period, $amount)) !== null) {
            return $error;
        }
        Database::query(
            'INSERT INTO module_prices (module_key, period, amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE period = VALUES(period), amount = VALUES(amount)',
            [$key, $period, number_format((float) $amount, 2, '.', '')]
        );
        AuditService::log($actorId, 'module.price', "{$key}: " . self::PERIODS[$period] . ' RM' . number_format((float) $amount, 2));
        return null;
    }
}
