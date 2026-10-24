<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Read side for shipments (the courier API lives in Shipping\NinjaVanGateway). */
final class ShipmentService
{
    /** Order ids (from $ids) that have a courier booking, active or cancelled. @return array<int,true> */
    public static function bookedAmong(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_fill_keys(array_map('intval', Database::query(
            "SELECT DISTINCT order_id FROM shipments WHERE order_id IN ({$in})", $ids
        )->fetchAll(\PDO::FETCH_COLUMN)), true);
    }

    /** Active courier bookings of several orders. @return array<int,array> order id => shipment row */
    public static function activeAmong(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::query("SELECT * FROM shipments WHERE active = 1 AND order_id IN ({$in})", $ids)->fetchAll() as $s) {
            $out[(int) $s['order_id']] = $s;
        }
        return $out;
    }

    /** Remember that the AWB of the order's active booking was printed (keeps the first time). */
    public static function markAwbPrinted(int $orderId): bool
    {
        $ship = self::active($orderId);
        if ($ship === null) {
            return false;
        }
        Database::query('UPDATE shipments SET awb_printed_at = COALESCE(awb_printed_at, NOW()) WHERE id = ?', [(int) $ship['id']]);
        return true;
    }

    /** Mark the active bookings of several orders as printed (keeps the first time). @return int how many were newly marked */
    public static function markAwbPrintedAmong(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return Database::query("UPDATE shipments SET awb_printed_at = COALESCE(awb_printed_at, NOW()) WHERE active = 1 AND order_id IN ({$in})", $ids)->rowCount();
    }

    public static function active(int $orderId): ?array
    {
        return Database::query('SELECT * FROM shipments WHERE order_id = ? AND active = 1', [$orderId])->fetch() ?: null;
    }

    public static function find(int $id): ?array
    {
        return Database::query('SELECT * FROM shipments WHERE id = ?', [$id])->fetch() ?: null;
    }

    public static function events(int $shipmentId): array
    {
        return Database::query('SELECT * FROM shipment_events WHERE shipment_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 50', [$shipmentId])->fetchAll();
    }

    /** All shipments for an order, newest first (cancelled ones included), for the staff view. */
    public static function history(int $orderId): array
    {
        return Database::query('SELECT * FROM shipments WHERE order_id = ? ORDER BY id DESC', [$orderId])->fetchAll();
    }
}
