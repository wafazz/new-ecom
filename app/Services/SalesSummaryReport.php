<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Sales totals for the CodexLure store-sales dashboard. Same definition as the admin dashboard
 * (DashboardService): sales = order totals, excluding cancelled orders, by the day the order was placed.
 * Day boundaries follow APP_TIMEZONE. Output has the same shape as the Rozeyana sales-summary API.
 */
final class SalesSummaryReport
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 366;

    /**
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable, from: string, to: string}|null $range
     * @return array<string, mixed>
     */
    public static function build(int $days = self::DEFAULT_DAYS, ?array $range = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $today = $now->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $monthStart = $today->modify('first day of this month');
        $yearStart = $today->setDate((int) $today->format('Y'), 1, 1);

        if ($range !== null) {
            $seriesStart = $range['start'];
            $seriesEnd = $range['end'];
        } else {
            $days = max(1, min(self::MAX_DAYS, $days));
            $seriesStart = $today->modify('-' . ($days - 1) . ' days');
            $seriesEnd = $tomorrow;
        }

        return [
            'store' => (string) config('app.name', 'Shop'),
            'currency' => (string) config('app.currency', 'MYR'),
            'timezone' => date_default_timezone_get(),
            'generated_at' => $now->format(DATE_ATOM),
            'counting_rule' => "status <> 'cancelled'; amount = order total",
            'totals' => [
                'daily' => ['date' => $today->format('Y-m-d')] + self::aggregate($today, $tomorrow),
                'monthly' => ['period' => $monthStart->format('Y-m')] + self::aggregate($monthStart, $today->modify('first day of next month')),
                'yearly' => ['period' => $yearStart->format('Y')] + self::aggregate($yearStart, $yearStart->modify('+1 year')),
                'total' => self::aggregate(null, null),
                'range' => $range === null
                    ? null
                    : ['from' => $range['from'], 'to' => $range['to']] + self::aggregate($seriesStart, $seriesEnd),
            ],
            'daily' => self::dailySeries($seriesStart, $seriesEnd),
        ];
    }

    /**
     * Validates from/to (YYYY-MM-DD, both inclusive). A missing "to" means today.
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable, from: string, to: string}
     * @throws InvalidArgumentException with a message the caller can show.
     */
    public static function parseRange(?string $from, ?string $to, DateTimeImmutable $today): array
    {
        $zone = $today->getTimezone();
        $start = self::parseDate((string) $from, $zone);
        if ($start === null) {
            throw new InvalidArgumentException('from must be a date as YYYY-MM-DD.');
        }
        $end = $to === null || $to === '' ? $today->setTime(0, 0) : self::parseDate($to, $zone);
        if ($end === null) {
            throw new InvalidArgumentException('to must be a date as YYYY-MM-DD.');
        }
        if ($end < $start) {
            throw new InvalidArgumentException('to must be the same day or after from.');
        }
        if ($start->diff($end)->days + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('The range can be at most ' . self::MAX_DAYS . ' days.');
        }

        return [
            'start' => $start,
            'end' => $end->modify('+1 day'),
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
        ];
    }

    private static function parseDate(string $value, \DateTimeZone $zone): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);

        return $d !== false && $d->format('Y-m-d') === $value ? $d : null;
    }

    /** @return array{orders: int, amount: float} Totals for [$from, $to); null bounds mean unbounded. */
    private static function aggregate(?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        [$sql, $params] = self::window(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS amount FROM orders WHERE status <> 'cancelled'",
            $from,
            $to
        );
        $row = Database::query($sql, $params)->fetch();

        return ['orders' => (int) $row['orders'], 'amount' => round((float) $row['amount'], 2)];
    }

    /** @return array<int, array{date: string, orders: int, amount: float}> One entry per day, zeros included. */
    private static function dailySeries(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        [$sql, $params] = self::window(
            "SELECT DATE(created_at) AS day, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS amount FROM orders WHERE status <> 'cancelled'",
            $start,
            $end
        );
        $byDay = [];
        foreach (Database::query($sql . ' GROUP BY DATE(created_at)', $params)->fetchAll() as $row) {
            $byDay[$row['day']] = ['orders' => (int) $row['orders'], 'amount' => round((float) $row['amount'], 2)];
        }

        $series = [];
        $days = (int) $start->diff($end)->days;
        for ($i = 0; $i < $days; $i++) {
            $date = $start->modify('+' . $i . ' days')->format('Y-m-d');
            $series[] = ['date' => $date] + ($byDay[$date] ?? ['orders' => 0, 'amount' => 0.0]);
        }

        return $series;
    }

    /** @return array{0: string, 1: array<int, string>} */
    private static function window(string $sql, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        $params = [];
        if ($from !== null) {
            $sql .= ' AND created_at >= ?';
            $params[] = $from->format('Y-m-d H:i:s');
        }
        if ($to !== null) {
            $sql .= ' AND created_at < ?';
            $params[] = $to->format('Y-m-d H:i:s');
        }

        return [$sql, $params];
    }
}
