<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Services\AddressService;
use App\Services\OrderService;
use App\Services\ReceiptService;
use App\Services\Shipping\NinjaVanGateway;
use App\Services\ShipmentService;
use App\Services\Payment\Payments;

final class OrderController extends ManageController
{
    private const PER_PAGE = 20;

    public function index(Request $request): void
    {
        $q = trim($request->str('q'));
        $status = $request->str('status');
        $page = max(1, (int) $request->input('page', 1));

        $where = ['1=1'];
        $args = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(order_no LIKE ? OR customer_email LIKE ? OR customer_name LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        if (isset(OrderService::FLOW[$status])) {
            $where[] = 'status = ?';
            $args[] = $status;
        }
        $receipt = $request->str('receipt') === '1'; // only orders with a bank transfer receipt waiting for a person
        if ($receipt) {
            $where[] = "payment_status = 'unpaid' AND status <> 'cancelled' AND id IN (SELECT order_id FROM payment_proofs WHERE status = 'submitted')";
        }
        $printed = $request->str('printed') === '1' && $status === 'processing'; // courier label printed: ready to move on
        if ($printed) {
            $where[] = 'id IN (SELECT order_id FROM shipments WHERE active = 1 AND awb_printed_at IS NOT NULL)';
        }
        $w = implode(' AND ', $where);
        $limit = self::PER_PAGE;
        $offset = ($page - 1) * $limit;

        $total = (int) Database::query("SELECT COUNT(*) FROM orders WHERE {$w}", $args)->fetchColumn();
        $rows = Database::query("SELECT * FROM orders WHERE {$w} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", $args)->fetchAll();

        $this->view('manage/orders/index', [
            'title' => 'Orders',
            'base' => $this->base(),
            'orders' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $limit)),
            'filters' => ['q' => $q, 'status' => $status, 'receipt' => $receipt ? '1' : '', 'printed' => $printed ? '1' : ''],
            'printedCount' => (int) Database::query("SELECT COUNT(*) FROM orders o WHERE o.status = 'processing' AND o.id IN (SELECT order_id FROM shipments WHERE active = 1 AND awb_printed_at IS NOT NULL)")->fetchColumn(),
            'waiting' => ReceiptService::waitingAmong(array_column($rows, 'id')),
            'proofs' => ReceiptService::latestAmong(array_column($rows, 'id')),
            'items' => OrderService::itemsAmong(array_column($rows, 'id')),
            'booked' => ShipmentService::bookedAmong(array_column($rows, 'id')),
            'shipments' => ShipmentService::activeAmong(array_column($rows, 'id')),
            'canUpdate' => Auth::can('orders.update'),
            'scripts' => ['order-proofs.js', 'order-edit.js', 'order-details.js', 'order-bulk.js'],
            'receiptCount' => ReceiptService::pendingCount(),
            'statuses' => array_keys(OrderService::FLOW),
            'counts' => Database::query('SELECT status, COUNT(*) n FROM orders GROUP BY status')->fetchAll(\PDO::FETCH_KEY_PAIR),
        ], 'portal');
    }

    public function show(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? \App\Core\Response::abort(404);
        $popup = (string) $request->input('popup', '') === '1'; // the order list opens this inside a popup
        $this->view('manage/orders/show', [
            'title' => 'Order ' . $order['order_no'],
            'base' => $this->base(),
            'order' => $order,
            'items' => OrderService::items((int) $order['id']),
            'events' => OrderService::events((int) $order['id']),
            'next' => $order['payment_method'] === 'cod'
                ? array_values(array_diff(OrderService::FLOW[$order['status']], ['completed'])) // COD completes on the courier's delivery
                : OrderService::FLOW[$order['status']],
            'payment' => Payments::find($order['payment_method']),
            'canUpdate' => Auth::can('orders.update'),
            'shipment' => $ship = ShipmentService::active((int) $order['id']),
            'shipEvents' => $ship ? ShipmentService::events((int) $ship['id']) : [],
            'shipBlock' => NinjaVanGateway::blocker($order),
            'shipHistory' => ShipmentService::history((int) $order['id']),
            'isAdmin' => $this->isAdmin(),
            'proofs' => ReceiptService::forOrder((int) $order['id'], true),
            'popup' => $popup,
            'scripts' => ['order-details.js'],
        ], $popup ? null : 'portal');
    }

    /** Open a customer's receipt (permission orders.view is checked by the route). */
    public function proofFile(Request $request, string $id, string $proofId): void
    {
        ReceiptService::send(ReceiptService::find((int) $id, (int) $proofId) ?? \App\Core\Response::abort(404));
    }

    public function approveProof(Request $request, string $id, string $proofId): void
    {
        $back = $this->base() . "/orders/{$id}#receipt";
        $error = ReceiptService::approve((int) $id, (int) $proofId, (int) Auth::user()['id']);
        $error !== null ? $this->backWithErrors($back, ['form' => $error]) : $this->success($back, 'Receipt approved and the order is marked paid.');
    }

    public function rejectProof(Request $request, string $id, string $proofId): void
    {
        $back = $this->base() . "/orders/{$id}#receipt";
        $error = ReceiptService::reject((int) $id, (int) $proofId, (int) Auth::user()['id'], $request->str('reason'));
        $error !== null ? $this->backWithErrors($back, ['form' => $error]) : $this->success($back, 'Receipt sent back to the customer.');
    }

    public function status(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? \App\Core\Response::abort(404);
        $note = trim($request->str('note'));
        if (mb_strlen($note) > 255) {
            $this->backWithErrors($this->base() . "/orders/{$order['id']}", ['form' => 'Note is too long (max 255).']);
        }
        $error = OrderService::transition((int) $order['id'], $request->str('status'), (int) Auth::user()['id'], $note);
        if ($error !== null) {
            $this->backWithErrors($this->base() . "/orders/{$order['id']}", ['form' => $error]);
        }
        $this->success($this->base() . "/orders/{$order['id']}", 'Order updated.');
    }

    /** Approve a pending order: a waiting bank transfer receipt is approved (marks paid), then the order moves to processing. */
    public function approve(Request $request, string $id): void
    {
        $back = $this->base() . '/orders?status=pending';
        $order = OrderService::find((int) $id) ?? \App\Core\Response::abort(404);
        if ($order['status'] !== 'pending') {
            $this->backWithErrors($back, ['form' => "This order is {$order['status']} and can't be approved."]);
        }
        $actor = (int) Auth::user()['id'];
        $proof = ReceiptService::latestAmong([(int) $order['id']])[(int) $order['id']] ?? null;
        if ($proof !== null && $proof['status'] === 'submitted') {
            $error = ReceiptService::approve((int) $order['id'], (int) $proof['id'], $actor);
            if ($error !== null) {
                $this->backWithErrors($back, ['form' => $error]);
            }
        }
        $error = OrderService::transition((int) $order['id'], 'processing', $actor);
        if ($error !== null) {
            $this->backWithErrors($back, ['form' => $error]);
        }
        $this->success($back, "{$order['order_no']} approved and moved to processing.");
    }

    /** Save the edit popup of a pending or processing order (the route checks orders.update). */
    public function details(Request $request, string $id): void
    {
        $back = $this->listBack($request);
        $in = [];
        foreach (['name', 'phone', 'line1', 'line2', 'city', 'state', 'postcode'] as $key) {
            $in[$key] = trim($request->str($key));
        }
        $errors = AddressService::validate($in);
        if ($errors !== []) {
            $this->backWithErrors($back, $errors);
        }
        $clean = ['customer_name' => $in['name'], 'customer_phone' => $in['phone'], 'ship_line1' => $in['line1'], 'ship_line2' => $in['line2'] ?: null, 'ship_city' => $in['city'], 'ship_state' => $in['state'], 'ship_postcode' => $in['postcode']];
        $error = OrderService::updateDetails((int) $id, $clean, (int) Auth::user()['id']);
        $error !== null ? $this->backWithErrors($back, ['form' => $error]) : $this->success($back, 'Order details saved.');
    }

    /** Where the orders list goes back to: the status tab the order was on. */
    private function listBack(Request $request): string
    {
        $from = in_array($request->str('from'), ['pending', 'processing'], true) ? $request->str('from') : 'pending';
        return $this->base() . '/orders?status=' . $from;
    }

    /** Move printed Processing orders on: COD to Shipped, paid non-COD to Completed (the route checks orders.update). */
    public function moveNext(Request $request): void
    {
        $back = $this->base() . '/orders?status=processing';
        $ids = OrderService::idsFrom((string) $request->input('ids', ''));
        if ($ids === []) {
            $this->backWithErrors($back, ['form' => 'Select at least one printed order first.']);
        }
        $r = OrderService::moveNext($ids, (int) Auth::user()['id']);
        $parts = [];
        if ($r['shipped'] > 0) {
            $parts[] = "{$r['shipped']} COD order(s) moved to Shipped";
        }
        if ($r['completed'] > 0) {
            $parts[] = "{$r['completed']} paid non-COD order(s) moved to Completed";
        }
        if ($parts === []) {
            $this->backWithErrors($back, ['form' => implode(' ', $r['skipped']) ?: 'Nothing was moved.']);
        }
        $msg = implode('; ', $parts) . '.' . ($r['skipped'] !== [] ? ' Skipped: ' . implode(' ', $r['skipped']) : '');
        $this->success($back, $msg);
    }

    /** Delete a pending or processing order that has no courier booking (the route checks orders.update). */
    public function destroy(Request $request, string $id): void
    {
        $back = $this->listBack($request);
        $error = OrderService::delete((int) $id, (int) Auth::user()['id']);
        $error !== null ? $this->backWithErrors($back, ['form' => $error]) : $this->success($back, 'Order deleted and its stock returned.');
    }

    public function paid(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? \App\Core\Response::abort(404);
        $error = OrderService::markPaid((int) $order['id'], (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors($this->base() . "/orders/{$order['id']}", ['form' => $error]);
        }
        $this->success($this->base() . "/orders/{$order['id']}", 'Marked as paid.');
    }
}
