<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Read-only sales figures. A sale is any order that was not cancelled; its amount is the order total
 * (items, shipping and discounts), the same figure the Orders list shows. Dates are inclusive.
 */
final class SalesReportService
{
    public const MAX_DAYS = 366;

    /**
     * Turns the query string into a valid window. Bad input falls back to the last 30 days; a reversed
     * range is swapped; a window longer than MAX_DAYS is cut to MAX_DAYS.
     * @return array{from:string, to:string, grain:string}
     */
    public static function window(?string $from, ?string $to, ?string $grain): array
    {
        $day = fn(?string $v): ?string => ($v !== null && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v)) && $d->format('Y-m-d') === $v) ? $v : null;
        $f = $day($from) ?? date('Y-m-d', strtotime('-29 days'));
        $t = $day($to) ?? date('Y-m-d');
        if ($f > $t) {
            [$f, $t] = [$t, $f];
        }
        $span = (int) ((strtotime($t) - strtotime($f)) / 86400);
        if ($span >= self::MAX_DAYS) {
            $f = date('Y-m-d', strtotime($t . ' -' . (self::MAX_DAYS - 1) . ' days'));
        }
        return ['from' => $f, 'to' => $t, 'grain' => $grain === 'month' ? 'month' : 'day'];
    }

    /** @return array{orders:int, sales:float, average:float} */
    public static function summary(string $from, string $to): array
    {
        $r = Database::query(
            "SELECT COUNT(*) orders, COALESCE(SUM(total), 0) sales FROM orders WHERE status <> 'cancelled' AND created_at >= ? AND created_at < ?",
            [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
        )->fetch();
        $orders = (int) $r['orders'];
        $sales = (float) $r['sales'];
        return ['orders' => $orders, 'sales' => $sales, 'average' => $orders > 0 ? $sales / $orders : 0.0];
    }

    /** @return array<int,array{period:string, orders:int, sales:float}> oldest first */
    public static function byPeriod(string $from, string $to, string $grain): array
    {
        $fmt = $grain === 'month' ? '%Y-%m' : '%Y-%m-%d';
        $rows = Database::query(
            "SELECT DATE_FORMAT(created_at, '{$fmt}') period, COUNT(*) orders, SUM(total) sales FROM orders
             WHERE status <> 'cancelled' AND created_at >= ? AND created_at < ? GROUP BY period ORDER BY period",
            [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
        )->fetchAll();
        return array_map(fn($r) => ['period' => (string) $r['period'], 'orders' => (int) $r['orders'], 'sales' => (float) $r['sales']], $rows);
    }

    /** @return array<int,array{name:string, units:int, sales:float}> best sellers by amount */
    public static function topProducts(string $from, string $to, int $limit = 10): array
    {
        $rows = Database::query(
            "SELECT oi.product_name name, SUM(oi.qty) units, SUM(oi.line_total) sales
             FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE o.status <> 'cancelled' AND o.created_at >= ? AND o.created_at < ?
             GROUP BY oi.product_name ORDER BY sales DESC LIMIT " . (int) $limit,
            [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
        )->fetchAll();
        return array_map(fn($r) => ['name' => (string) $r['name'], 'units' => (int) $r['units'], 'sales' => (float) $r['sales']], $rows);
    }

    /** @return array<int,array{method:string, orders:int, sales:float}> */
    public static function byPayment(string $from, string $to): array
    {
        $rows = Database::query(
            "SELECT payment_method m, COUNT(*) orders, SUM(total) sales FROM orders
             WHERE status <> 'cancelled' AND created_at >= ? AND created_at < ? GROUP BY payment_method ORDER BY sales DESC",
            [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
        )->fetchAll();
        return array_map(fn($r) => ['method' => (string) $r['m'], 'orders' => (int) $r['orders'], 'sales' => (float) $r['sales']], $rows);
    }
}
