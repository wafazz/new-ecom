<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;

/**
 * Suppliers and purchase orders for physical, single-option products. A purchase order is a draft, then sent, then
 * part-received or received. Stock goes up only when a receipt is recorded, one line at a time, and a receipt cannot
 * take a line past the quantity ordered.
 */
final class PurchaseOrderService
{
    public const LOW_STOCK = 5;
    public const MAX_LINES = 20;

    /** @return string|null error */
    public static function createSupplier(string $name, string $contact, string $leadDays, int $actorId): ?string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            return 'Give the supplier a name of up to 120 characters.';
        }
        $days = trim($leadDays) === '' ? null : (int) $leadDays;
        if ($days !== null && ($days < 0 || $days > 365)) {
            return 'Lead time is from 0 to 365 days.';
        }
        try {
            Database::query('INSERT INTO suppliers (name, contact, lead_days) VALUES (?, ?, ?)', [$name, mb_substr(trim($contact), 0, 190) ?: null, $days]);
        } catch (\PDOException) {
            return 'A supplier with that name already exists.';
        }
        AuditService::log($actorId, 'supplier.created', $name);
        return null;
    }

    /** Lines: [product_id, qty, unit_cost]. @return int|string purchase order id, or the error message */
    public static function createOrder(int $supplierId, array $lines, int $actorId): int|string
    {
        if (Database::query('SELECT COUNT(*) FROM suppliers WHERE id = ?', [$supplierId])->fetchColumn() == 0) {
            return 'Choose a supplier.';
        }
        $clean = [];
        foreach (array_slice($lines, 0, self::MAX_LINES) as $l) {
            $pid = (int) ($l['product_id'] ?? 0);
            $qty = (int) ($l['qty'] ?? 0);
            if ($pid < 1 && $qty < 1) {
                continue;
            }
            $product = Product::findActive($pid);
            if ($product === null || DigitalService::isDigital($product) || Database::query('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND deleted_at IS NULL', [$pid])->fetchColumn() > 0) {
                return 'Each line needs a live single product (not a digital one, and no options).';
            }
            if ($qty < 1 || $qty > StockService::MAX) {
                return 'Each line needs a quantity from 1 to ' . number_format(StockService::MAX) . '.';
            }
            $cost = trim((string) ($l['unit_cost'] ?? ''));
            if ($cost !== '' && !preg_match('/^\d{1,6}(\.\d{1,2})?$/', $cost)) {
                return 'Unit cost must be a price in RM, for example 12.50.';
            }
            $clean[] = ['product_id' => $pid, 'qty' => $qty, 'cost' => $cost === '' ? null : number_format((float) $cost, 2, '.', '')];
        }
        if ($clean === []) {
            return 'Add at least one line.';
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::query("INSERT INTO purchase_orders (supplier_id, status, ordered_on, created_by) VALUES (?, 'draft', CURDATE(), ?)", [$supplierId, $actorId]);
            $po = (int) Database::connection()->lastInsertId();
            foreach ($clean as $c) {
                Database::query('INSERT INTO purchase_order_items (po_id, product_id, qty_ordered, unit_cost) VALUES (?, ?, ?, ?)', [$po, $c['product_id'], $c['qty'], $c['cost']]);
            }
            AuditService::log($actorId, 'purchase_order.created', "PO {$po}: " . count($clean) . ' line(s)');
            $pdo->commit();
            return $po;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return string|null error */
    public static function send(int $poId, int $actorId): ?string
    {
        $n = Database::query("UPDATE purchase_orders SET status = 'sent', ordered_on = COALESCE(ordered_on, CURDATE()) WHERE id = ? AND status = 'draft'", [$poId])->rowCount();
        if ($n !== 1) {
            return 'Only a draft purchase order can be sent.';
        }
        AuditService::log($actorId, 'purchase_order.sent', "PO {$poId}");
        return null;
    }

    /**
     * Records a delivery. $received: item id => quantity arrived now. Stock goes up by each amount, and no line can go past
     * its ordered quantity. @return string|null error
     */
    public static function receive(int $poId, array $received, int $actorId): ?string
    {
        $po = Database::query('SELECT * FROM purchase_orders WHERE id = ?', [$poId])->fetch();
        if (!$po || !in_array($po['status'], ['sent', 'part_received'], true)) {
            return 'Only a sent purchase order can receive stock.';
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $items = Database::query('SELECT * FROM purchase_order_items WHERE po_id = ? FOR UPDATE', [$poId])->fetchAll();
            $byId = [];
            foreach ($items as $it) {
                $byId[(int) $it['id']] = $it;
            }
            $total = 0;
            foreach ($received as $itemId => $raw) {
                $qty = (int) trim((string) $raw);
                if ($qty < 1) {
                    continue;
                }
                if (!isset($byId[(int) $itemId])) {
                    $pdo->rollBack();
                    return 'A line on this order was not found. Reload and try again.';
                }
                $it = $byId[(int) $itemId];
                if ($it['qty_received'] + $qty > $it['qty_ordered']) {
                    $pdo->rollBack();
                    return "More arrived than was ordered for one line ({$it['qty_ordered']} ordered, {$it['qty_received']} already received).";
                }
                StockService::delta((int) $it['product_id'], null, $qty, 'restock', null, $actorId, "Purchase order #{$poId}");
                Database::query('UPDATE purchase_order_items SET qty_received = qty_received + ? WHERE id = ?', [$qty, (int) $it['id']]);
                $total += $qty;
            }
            if ($total === 0) {
                $pdo->rollBack();
                return 'Enter how many arrived on at least one line.';
            }
            $left = (int) Database::query('SELECT COUNT(*) FROM purchase_order_items WHERE po_id = ? AND qty_received < qty_ordered', [$poId])->fetchColumn();
            Database::query('UPDATE purchase_orders SET status = ? WHERE id = ?', [$left === 0 ? 'received' : 'part_received', $poId]);
            AuditService::log($actorId, 'purchase_order.received', "PO {$poId}: {$total} unit(s) received");
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return null;
    }

    /** Products at or below the low-stock level, with a suggested order quantity. */
    public static function suggestions(): array
    {
        return Database::query(
            "SELECT id, name, sku, stock FROM products WHERE deleted_at IS NULL AND status = 'active' AND product_type <> 'digital' AND stock <= ? ORDER BY stock, name LIMIT 50",
            [self::LOW_STOCK]
        )->fetchAll();
    }

    public static function listing(): array
    {
        return Database::query('SELECT po.*, s.name supplier, (SELECT SUM(qty_ordered) FROM purchase_order_items i WHERE i.po_id = po.id) ordered, (SELECT SUM(qty_received) FROM purchase_order_items i WHERE i.po_id = po.id) received FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id ORDER BY po.id DESC LIMIT 100')->fetchAll();
    }
}
