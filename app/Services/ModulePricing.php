<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** What each module costs and how often it is billed. Set by the superadmin. An amount of 0 means the module is free. */
final class ModulePricing
{
    public const PERIODS = ['one_time' => 'One-time', 'monthly' => 'Monthly', 'yearly' => 'Yearly'];
    public const MAX_AMOUNT = 99999.99;

    /** The plans a module offers: period => list price (RM). A period with no amount is not offered. @return array<string,float> */
    public static function plans(string $key): array
    {
        $out = [];
        foreach (Database::query('SELECT period, amount FROM module_prices WHERE module_key = ? AND amount > 0 ORDER BY FIELD(period, \'one_time\', \'monthly\', \'yearly\')', [$key])->fetchAll() as $r) {
            $out[(string) $r['period']] = (float) $r['amount'];
        }
        return $out;
    }

    /** A module with no plan offered needs no payment. */
    public static function isFree(string $key): bool
    {
        return self::plans($key) === [];
    }

    /** The list price of one plan, or null when that plan is not offered. */
    public static function listPrice(string $key, string $period): ?float
    {
        return self::plans($key)[$period] ?? null;
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

    public const MIN_PERCENT = 1;
    public const MAX_PERCENT = 99;
    public const MAX_FIXED = 99999.99;
    public const MAX_DISCOUNT_DAYS = 730;
    public const KINDS = ['percent' => 'Percentage off', 'fixed' => 'Fixed price (RM)'];

    /**
     * @return string|null error message, or null when valid. $nowTs is passed in so the check is testable.
     * A percentage is 1 to 99. A fixed price is RM0.01 to RM99999.99.
     */
    public static function validateDiscount(string $scope, string $kind, string $value, string $endsAt, int $nowTs): ?string
    {
        if ($scope !== 'global' && !isset(Modules::LIST[$scope])) {
            return 'Unknown module.';
        }
        if ($kind === 'percent') {
            if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $value) || (float) $value < self::MIN_PERCENT || (float) $value > self::MAX_PERCENT) {
                return 'Enter a percentage from ' . self::MIN_PERCENT . ' to ' . self::MAX_PERCENT . '.';
            }
        } elseif ($kind === 'fixed') {
            if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $value) || (float) $value < 0.01 || (float) $value > self::MAX_FIXED) {
                return 'Enter a fixed price from RM0.01 to RM99,999.99.';
            }
        } else {
            return 'Choose a percentage or a fixed price.';
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
    public static function setDiscount(string $scope, string $kind, string $value, string $endsAt, int $actorId): ?string
    {
        $error = self::validateDiscount($scope, $kind, $value, $endsAt, time());
        if ($error !== null) {
            return $error;
        }
        $end = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endsAt)->format('Y-m-d H:i:s');
        $v = number_format((float) $value, 2, '.', '');
        Database::query(
            'INSERT INTO module_discounts (scope, kind, value, percent, ends_at, created_by) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE kind = VALUES(kind), value = VALUES(value), percent = VALUES(percent), ends_at = VALUES(ends_at), created_by = VALUES(created_by)',
            [$scope, $kind, $v, $kind === 'percent' ? $v : null, $end, $actorId]
        );
        AuditService::log($actorId, 'module.discount', ($scope === 'global' ? 'all modules' : $scope) . ': ' . self::describe(['kind' => $kind, 'value' => (float) $v]) . " until {$end}");
        ModuleBillingService::flushCache();
        return null;
    }

    public static function clearDiscount(string $scope, int $actorId): void
    {
        Database::query('DELETE FROM module_discounts WHERE scope = ?', [$scope]);
        AuditService::log($actorId, 'module.discount', ($scope === 'global' ? 'all modules' : $scope) . ': discount removed');
        ModuleBillingService::flushCache();
    }

    /** The price a module drops to under a discount. A fixed price never goes above the normal price. */
    public static function finalFor(float $list, string $kind, float $value): float
    {
        return $kind === 'fixed' ? round(min($list, $value), 2) : round($list * (1 - $value / 100), 2);
    }

    /** "20% off" or "RM50 fixed price", for display. */
    public static function describe(array $d): string
    {
        return $d['kind'] === 'fixed'
            ? 'RM' . number_format((float) $d['value'], 2) . ' fixed price'
            : rtrim(rtrim(number_format((float) $d['value'], 2, '.', ''), '0'), '.') . '% off';
    }

    /** The discount that applies to a module now: its own if active, else the global one, else null. */
    public static function activeDiscount(string $key): ?array
    {
        $r = Database::query(
            'SELECT scope, kind, value, ends_at FROM module_discounts WHERE scope IN (?, ?) AND ends_at > ? ORDER BY (scope = ?) DESC LIMIT 1',
            [$key, 'global', date('Y-m-d H:i:s'), $key]
        )->fetch();
        return $r ? ['scope' => (string) $r['scope'], 'kind' => (string) $r['kind'], 'value' => (float) $r['value'], 'ends_at' => (string) $r['ends_at']] : null;
    }

    /** The discount row for one scope, active or not (for the superadmin page). */
    public static function discountRow(string $scope): ?array
    {
        $r = Database::query('SELECT kind, value, ends_at FROM module_discounts WHERE scope = ?', [$scope])->fetch();
        return $r ? ['kind' => (string) $r['kind'], 'value' => (float) $r['value'], 'ends_at' => (string) $r['ends_at'], 'active' => (string) $r['ends_at'] > date('Y-m-d H:i:s')] : null;
    }

    /**
     * What one plan of a module costs now: the list price, the discount that applies, and the final amount.
     * @return array{period:string, list:float, final:float, kind:?string, value:float, ends_at:?string, scope:?string}
     */
    public static function quote(string $key, string $period): array
    {
        $list = self::listPrice($key, $period) ?? 0.0;
        $d = self::activeDiscount($key);
        $final = $d === null ? $list : self::finalFor($list, $d['kind'], $d['value']);
        return [
            'period' => $period,
            'list' => $list,
            'final' => max(0.0, (float) $final),
            'kind' => $d['kind'] ?? null,
            'value' => $d['value'] ?? 0.0,
            'ends_at' => $d['ends_at'] ?? null,
            'scope' => $d['scope'] ?? null,
        ];
    }

    /** Sets one plan's price. An amount of 0 takes that plan off offer. @return string|null error */
    public static function set(string $key, string $period, string $amount, int $actorId): ?string
    {
        if (!isset(Modules::LIST[$key])) {
            return 'Unknown module.';
        }
        $amount = trim($amount) === '' ? '0' : trim($amount);
        if (($error = self::validate($period, $amount)) !== null) {
            return $error;
        }
        if ((float) $amount <= 0) {
            Database::query('DELETE FROM module_prices WHERE module_key = ? AND period = ?', [$key, $period]);
            AuditService::log($actorId, 'module.price', "{$key}: " . self::PERIODS[$period] . ' plan taken off offer');
        } else {
            Database::query(
                'INSERT INTO module_prices (module_key, period, amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)',
                [$key, $period, number_format((float) $amount, 2, '.', '')]
            );
            AuditService::log($actorId, 'module.price', "{$key}: " . self::PERIODS[$period] . ' RM' . number_format((float) $amount, 2));
        }
        ModuleBillingService::flushCache();
        return null;
    }

    /**
     * Saves all three plans of a module from the form (blank or 0 = not offered).
     * @param array<string,string> $amounts period => amount
     * @return array<string,string> errors by period (empty when saved)
     */
    public static function setPlans(string $key, array $amounts, int $actorId): array
    {
        $errors = [];
        foreach (array_keys(self::PERIODS) as $period) {
            $err = self::set($key, $period, (string) ($amounts[$period] ?? '0'), $actorId);
            if ($err !== null) {
                $errors[$period] = $err;
            }
        }
        return $errors;
    }
}
