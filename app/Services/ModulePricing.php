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

    public const MIN_DISCOUNT = 1;
    public const MAX_DISCOUNT = 99;
    public const MAX_DISCOUNT_DAYS = 730;

    /** @return string|null error message, or null when valid. $nowTs is passed in so the check is testable. */
    public static function validateDiscount(string $scope, string $percent, string $endsAt, int $nowTs): ?string
    {
        if ($scope !== 'global' && !isset(Modules::LIST[$scope])) {
            return 'Unknown module.';
        }
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $percent) || (float) $percent < self::MIN_DISCOUNT || (float) $percent > self::MAX_DISCOUNT) {
            return 'Enter a discount from ' . self::MIN_DISCOUNT . '% to ' . self::MAX_DISCOUNT . '%.';
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endsAt);
        if ($d === false || $d->format('Y-m-d\TH:i') !== $endsAt) {
            return 'Pick the date and time the discount ends.';
        }
        if ($d->getTimestamp() <= $nowTs) {
            return 'The end time must be in the future.';
        }
        if ($d->getTimestamp() > $nowTs + self::MAX_DISCOUNT_DAYS * 86400) {
            return 'A discount can run for up to 2 years.';
        }
        return null;
    }

    /** Saves a discount for "global" or one module, replacing the one already there. @return string|null error */
    public static function setDiscount(string $scope, string $percent, string $endsAt, int $actorId): ?string
    {
        $error = self::validateDiscount($scope, $percent, $endsAt, time());
        if ($error !== null) {
            return $error;
        }
        $end = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endsAt)->format('Y-m-d H:i:s');
        Database::query(
            'INSERT INTO module_discounts (scope, percent, ends_at, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE percent = VALUES(percent), ends_at = VALUES(ends_at), created_by = VALUES(created_by)',
            [$scope, number_format((float) $percent, 2, '.', ''), $end, $actorId]
        );
        AuditService::log($actorId, 'module.discount', ($scope === 'global' ? 'all modules' : $scope) . ": {$percent}% until {$end}");
        ModuleBillingService::flushCache();
        return null;
    }

    public static function clearDiscount(string $scope, int $actorId): void
    {
        Database::query('DELETE FROM module_discounts WHERE scope = ?', [$scope]);
        AuditService::log($actorId, 'module.discount', ($scope === 'global' ? 'all modules' : $scope) . ': discount removed');
        ModuleBillingService::flushCache();
    }

    /** The discount that applies to a module now: its own if active, else the global one, else null. */
    public static function activeDiscount(string $key): ?array
    {
        $r = Database::query(
            'SELECT scope, percent, ends_at FROM module_discounts WHERE scope IN (?, ?) AND ends_at > ? ORDER BY (scope = ?) DESC LIMIT 1',
            [$key, 'global', date('Y-m-d H:i:s'), $key]
        )->fetch();
        return $r ? ['scope' => (string) $r['scope'], 'percent' => (float) $r['percent'], 'ends_at' => (string) $r['ends_at']] : null;
    }

    /** The discount row for one scope, active or not (for the superadmin page). */
    public static function discountRow(string $scope): ?array
    {
        $r = Database::query('SELECT percent, ends_at FROM module_discounts WHERE scope = ?', [$scope])->fetch();
        return $r ? ['percent' => (float) $r['percent'], 'ends_at' => (string) $r['ends_at'], 'active' => (string) $r['ends_at'] > date('Y-m-d H:i:s')] : null;
    }

    /**
     * What a module costs now: the list price, the discount that applies, and the final amount.
     * @return array{period:string, list:float, percent:float, final:float, ends_at:?string, scope:?string}
     */
    public static function quote(string $key): array
    {
        $price = self::price($key);
        $d = self::activeDiscount($key);
        $final = $d === null ? $price['amount'] : round($price['amount'] * (1 - $d['percent'] / 100), 2);
        return [
            'period' => $price['period'],
            'list' => $price['amount'],
            'percent' => $d['percent'] ?? 0.0,
            'final' => max(0.0, (float) $final),
            'ends_at' => $d['ends_at'] ?? null,
            'scope' => $d['scope'] ?? null,
        ];
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
        ModuleBillingService::flushCache();
        return null;
    }
}
