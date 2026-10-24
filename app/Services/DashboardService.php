<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Payment\Payments;

/**
 * Numbers for the portal dashboards. Everything here is computed from real orders; nothing is
 * estimated. "Sales" = order totals excluding cancelled orders, by the day the order was placed.
 */
final class DashboardService
{
    public const RANGES = [7, 30, 90];
    private const LOW_STOCK = 5;

    // ---- admin ------------------------------------------------------------------------------

    /** @return array<string,mixed> JSON-ready payload for the admin dashboard */
    public static function admin(int $days, ?\DateTimeImmutable $today = null): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : 30;
        $today ??= new \DateTimeImmutable('today');
        $start = $today->modify('-' . ($days - 1) . ' days');
        $prevStart = $start->modify("-{$days} days");
        $endExcl = $today->modify('+1 day');

        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = $start->modify("+{$i} days")->format('Y-m-d');
        }

        $daily = fn(string $sql, array $args) => array_column(Database::query($sql, $args)->fetchAll(), null, 'd');
        $win = [$prevStart->format('Y-m-d'), $endExcl->format('Y-m-d')];
        $orders = $daily("SELECT DATE(created_at) d, COUNT(*) n, COALESCE(SUM(total),0) sales FROM orders WHERE status <> 'cancelled' AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)", $win);
        $profit = $daily("SELECT DATE(o.created_at) d, COALESCE(SUM((i.unit_price - i.base_price) * i.qty),0) p FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.status <> 'cancelled' AND o.created_at >= ? AND o.created_at < ? GROUP BY DATE(o.created_at)", $win);
        $custs = $daily("SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE role = 'member' AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)", $win);

        $series = function (array $rows, string $col, bool $money) use ($days, $start, $prevStart): array {
            $cur = $prev = [];
            for ($i = 0; $i < $days; $i++) {
                $cd = $start->modify("+{$i} days")->format('Y-m-d');
                $pd = $prevStart->modify("+{$i} days")->format('Y-m-d');
                $cur[] = $money ? round((float) ($rows[$cd][$col] ?? 0), 2) : (int) ($rows[$cd][$col] ?? 0);
                $prev[] = $money ? round((float) ($rows[$pd][$col] ?? 0), 2) : (int) ($rows[$pd][$col] ?? 0);
            }
            return ['cur' => $cur, 'prev' => $prev, 'total' => array_sum($cur), 'prevTotal' => array_sum($prev)];
        };
        $sales = $series($orders, 'sales', true);
        $count = $series($orders, 'n', false);
        $gp = $series($profit, 'p', true);
        $cu = $series($custs, 'n', false);

        // Average order value per day (only where there were orders), for the sparkline.
        $aov = ['cur' => [], 'prev' => []];
        foreach (['cur', 'prev'] as $k) {
            foreach ($sales[$k] as $i => $s) {
                $aov[$k][] = $count[$k][$i] > 0 ? round($s / $count[$k][$i], 2) : 0.0;
            }
        }
        $aov['total'] = $count['total'] > 0 ? round($sales['total'] / $count['total'], 2) : 0.0;
        $aov['prevTotal'] = $count['prevTotal'] > 0 ? round($sales['prevTotal'] / $count['prevTotal'], 2) : 0.0;

        $period = [$start->format('Y-m-d'), $endExcl->format('Y-m-d')];
        $status = Database::query('SELECT status, COUNT(*) n FROM orders WHERE created_at >= ? AND created_at < ? GROUP BY status', $period)->fetchAll(\PDO::FETCH_KEY_PAIR);
        $statusRows = [];
        foreach (array_keys(OrderService::FLOW) as $s) {
            $statusRows[] = ['key' => $s, 'label' => ucfirst($s), 'value' => (int) ($status[$s] ?? 0), 'href' => '/admin/orders?status=' . $s];
        }
        $products = [];
        foreach (Database::query(
            "SELECT i.product_id, i.product_name, SUM(i.qty) units, SUM(i.line_total) sales FROM order_items i JOIN orders o ON o.id = i.order_id
             WHERE o.status <> 'cancelled' AND o.created_at >= ? AND o.created_at < ? GROUP BY i.product_id, i.product_name ORDER BY sales DESC LIMIT 6",
            $period
        )->fetchAll() as $r) {
            $products[] = ['label' => $r['product_name'], 'value' => round((float) $r['sales'], 2), 'units' => (int) $r['units'], 'href' => $r['product_id'] ? '/admin/products/' . $r['product_id'] . '/edit' : null];
        }
        $payments = [];
        foreach (Database::query(
            "SELECT payment_method, COUNT(*) n, SUM(total) sales FROM orders WHERE status <> 'cancelled' AND created_at >= ? AND created_at < ? GROUP BY payment_method ORDER BY sales DESC",
            $period
        )->fetchAll() as $r) {
            $payments[] = ['label' => Payments::label($r['payment_method']), 'value' => round((float) $r['sales'], 2), 'orders' => (int) $r['n'], 'href' => null];
        }

        return [
            'days' => $days,
            'rangeLabel' => $start->format('j M') . ' – ' . $today->format('j M'),
            'labels' => $dates,
            'metrics' => [
                'sales' => ['label' => 'Sales', 'money' => true] + $sales,
                'orders' => ['label' => 'Orders', 'money' => false] + $count,
                'profit' => ['label' => 'Gross profit', 'money' => true] + $gp,
                'aov' => ['label' => 'Average order', 'money' => true] + $aov,
                'customers' => ['label' => 'New members', 'money' => false] + $cu,
            ],
            'status' => $statusRows,
            'products' => $products,
            'payments' => $payments,
            'attention' => self::attention('admin'),
            'setup' => self::setup(),
            'recent' => self::recent(8, '/admin'),
            'lowStock' => self::lowStock('/admin'),
            'now' => time(),
        ];
    }

    // ---- staff / shared ops view -----------------------------------------------------------------

    /** Work queue + recent orders, restricted to what this staff member may open. */
    public static function ops(string $base, bool $canOrders, bool $canProducts): array
    {
        $status = [];
        if ($canOrders) {
            $rows = Database::query('SELECT status, COUNT(*) n FROM orders WHERE created_at >= (CURDATE() - INTERVAL 29 DAY) GROUP BY status')->fetchAll(\PDO::FETCH_KEY_PAIR);
            foreach (array_keys(OrderService::FLOW) as $s) {
                $status[] = ['key' => $s, 'label' => ucfirst($s), 'value' => (int) ($rows[$s] ?? 0), 'href' => $base . '/orders?status=' . $s];
            }
        }
        return [
            'attention' => self::attention('staff', $base, $canOrders, $canProducts),
            'status' => $status,
            'recent' => $canOrders ? self::recent(8, $base) : [],
            'lowStock' => $canProducts ? self::lowStock($base) : ['count' => 0, 'rows' => []],
            'canOrders' => $canOrders,
            'now' => time(),
        ];
    }

    /**
     * What needs a person right now. Only non-zero items are returned, most urgent first.
     * @return array<int,array{key:string,label:string,count:int,hint:?string,href:string,tone:string}>
     */
    public static function attention(string $who, string $base = '/admin', bool $canOrders = true, bool $canProducts = true): array
    {
        $one = fn(string $sql) => Database::query($sql)->fetch();
        $items = [];
        $push = function (string $key, string $label, int $count, ?string $hint, string $href, string $tone = 'warn') use (&$items) {
            if ($count > 0) {
                $items[] = compact('key', 'label', 'count', 'hint', 'href', 'tone');
            }
        };
        if ($who === 'admin') {
            $r = (int) $one("SELECT COUNT(*) c FROM orders WHERE status IN ('cancelled', 'returned') AND payment_status = 'paid'")['c'];
            $push('refunds', 'Refunds to issue (paid, then cancelled or returned)', $r, null, '/admin/orders?status=cancelled', 'crit');
        }
        if ($canOrders) {
            $p = $one("SELECT COUNT(*) c, MIN(created_at) oldest FROM orders WHERE status = 'pending' AND (payment_method = 'cod' OR payment_status = 'paid')");
            $push('pending', 'New orders to process', (int) $p['c'], $p['oldest'] ? 'oldest placed ' . self::ago((int) strtotime((string) $p['oldest'])) : null, $base . '/orders?status=pending');
            $ship = (int) $one("SELECT COUNT(*) c FROM orders o WHERE o.status = 'processing' AND (o.payment_method = 'cod' OR o.payment_status = 'paid') AND NOT EXISTS (SELECT 1 FROM shipments s WHERE s.order_id = o.id AND s.active = 1)")['c'];
            $push('ship', 'Orders ready to ship', $ship, 'create a NinjaVan shipment or mark shipped', $base . '/orders?status=processing');
            $rc = ReceiptService::pendingCount();
            $push('receipts', 'Bank transfer receipts to check', $rc, 'approve to mark the order paid, or send it back', $base . '/orders?receipt=1', 'crit');
            $wait = (int) $one("SELECT COUNT(*) c FROM orders WHERE status IN ('pending','processing') AND payment_method <> 'cod' AND payment_status = 'unpaid'")['c'];
            $push('unpaid', 'Waiting for payment', $wait, 'bank transfers and online payments', $base . '/orders?status=pending', 'info');
        }
        if ($who === 'admin') {
            $c = (int) $one("SELECT COUNT(*) c FROM affiliate_commissions WHERE status = 'pending'")['c'];
            $push('commissions', 'Affiliate commissions to approve', $c, null, '/admin/commissions?status=pending');
        }
        if ($canProducts) {
            $low = self::lowStock($base);
            $push('stock', 'Products low on stock (' . self::LOW_STOCK . ' or fewer)', $low['count'], null, $base . '/stock?level=low', 'info');
        }
        $order = ['crit' => 0, 'warn' => 1, 'info' => 2];
        usort($items, fn($a, $b) => $order[$a['tone']] <=> $order[$b['tone']]);
        return $items;
    }

    /** @return array{count:int, rows:array} lowest-stock sellable products / variants */
    public static function lowStock(string $base): array
    {
        $n = self::LOW_STOCK;
        $sql = "SELECT id, name, sku, stock FROM (
                  SELECT p.id, p.name, NULL AS sku, p.stock FROM products p
                    WHERE p.deleted_at IS NULL AND p.status = 'active' AND p.stock <= {$n}
                      AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL)
                  UNION ALL
                  SELECT p.id, p.name, v.sku, v.stock FROM product_variants v JOIN products p ON p.id = v.product_id
                    WHERE v.deleted_at IS NULL AND p.deleted_at IS NULL AND p.status = 'active' AND v.stock <= {$n}
                ) t ORDER BY stock ASC, name ASC";
        $all = Database::query($sql)->fetchAll();
        $rows = array_map(fn($r) => ['label' => $r['name'] . ($r['sku'] ? ' · ' . $r['sku'] : ''), 'stock' => (int) $r['stock'], 'href' => $base . '/stock?product=' . $r['id']], array_slice($all, 0, 6));
        return ['count' => count($all), 'rows' => $rows];
    }

    public static function recent(int $limit, string $base): array
    {
        $out = [];
        foreach (Database::query('SELECT id, order_no, customer_name, total, status, payment_status, payment_method, created_at FROM orders ORDER BY id DESC LIMIT ' . (int) $limit)->fetchAll() as $r) {
            $out[] = [
                'no' => $r['order_no'], 'name' => $r['customer_name'], 'total' => (float) $r['total'], 'status' => $r['status'],
                'paid' => $r['payment_status'] === 'paid', 'method' => Payments::label($r['payment_method']),
                'ts' => (int) strtotime((string) $r['created_at']), 'href' => $base . '/orders/' . $r['id'],
            ];
        }
        return $out;
    }

    /** Setup checklist for a new shop. Each item: done flag + where to fix it. */
    public static function setup(): array
    {
        $products = (int) Database::query("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND status = 'active'")->fetchColumn();
        $bp = BillplzSettings::overview();
        $nv = NinjaVanSettings::overview();
        $bpDone = $bp['api_key_set'] && $bp['x_signature_key_set'] && $bp['collection_id'] !== '' && $bp['verified'] === 'ok';
        $nvDone = $nv['client_secret_set'] && $nv['client_id'] !== '' && $nv['sender_complete'] && $nv['verified'] === 'ok';
        return [
            ['label' => 'Add your first active product', 'done' => $products > 0, 'href' => '/admin/products/create'],
            ['label' => 'Add your bank account details (for Bank transfer)', 'done' => !PaymentSettings::enabled('bank_transfer') || PaymentSettings::bankComplete(), 'href' => '/admin/payments'],
            ['label' => 'Connect Billplz and pass the test', 'done' => $bpDone, 'href' => '/admin/payments/billplz'],
            ['label' => 'Connect NinjaVan, add a pickup address, test', 'done' => $nvDone, 'href' => '/admin/shipping/ninjavan'],
        ];
    }

    // ---- member -----------------------------------------------------------------------------

    public static function member(int $userId): array
    {
        $totals = Database::query("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total END),0) spent FROM orders WHERE user_id = ?", [$userId])->fetch();
        $recent = Database::query('SELECT id, order_no, total, status, payment_status, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5', [$userId])->fetchAll();
        $active = Database::query("SELECT * FROM orders WHERE user_id = ? AND status IN ('pending','processing','shipped') ORDER BY id DESC LIMIT 1", [$userId])->fetch() ?: null;

        $track = null;
        if ($active) {
            $events = Database::query('SELECT event, MIN(created_at) at FROM order_events WHERE order_id = ? GROUP BY event', [$active['id']])->fetchAll(\PDO::FETCH_KEY_PAIR);
            $steps = [];
            foreach (['placed' => 'Placed', 'processing' => 'Processing', 'shipped' => 'Shipped', 'completed' => 'Delivered'] as $k => $label) {
                $steps[] = ['key' => $k, 'label' => $label, 'at' => isset($events[$k]) ? (int) strtotime((string) $events[$k]) : null];
            }
            $ship = ShipmentService::active((int) $active['id']);
            $track = ['order' => $active, 'steps' => $steps, 'shipment' => $ship, 'current' => array_search($active['status'], ['pending', 'processing', 'shipped', 'completed'], true)];
        }

        $code = (string) Database::query('SELECT affiliate_code FROM users WHERE id = ?', [$userId])->fetchColumn();
        $aff = null;
        if ($code !== '') {
            $clicks = array_column(Database::query('SELECT DATE(clicked_at) d, COUNT(*) n FROM affiliate_clicks WHERE affiliate_id = ? AND clicked_at >= (CURDATE() - INTERVAL 29 DAY) GROUP BY DATE(clicked_at)', [$userId])->fetchAll(), 'n', 'd');
            $series = [];
            for ($i = 29; $i >= 0; $i--) {
                $series[] = (int) ($clicks[date('Y-m-d', strtotime("-{$i} days"))] ?? 0);
            }
            $aff = ['code' => $code, 'link' => AffiliateService::link($code), 'series' => $series, 'stats' => AffiliateService::stats($userId)];
        }
        return [
            'orders' => (int) $totals['n'], 'spent' => (float) $totals['spent'],
            'wishlist' => (int) Database::query('SELECT COUNT(*) FROM wishlist_items WHERE user_id = ?', [$userId])->fetchColumn(),
            'addresses' => AddressService::count($userId),
            'recent' => $recent, 'track' => $track, 'affiliate' => $aff,
        ];
    }

    // ---- helpers ------------------------------------------------------------------------------

    public static function ago(int $ts, ?int $now = null): string
    {
        $d = max(0, ($now ?? time()) - $ts);
        return match (true) {
            $d < 90 => 'just now',
            $d < 3600 => intdiv($d, 60) . ' min ago',
            $d < 86400 => intdiv($d, 3600) . ' h ago',
            default => intdiv($d, 86400) . ' d ago',
        };
    }
}
