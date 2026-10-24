<?php
declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\Concerns\HandlesReceipts;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\OrderService;
use App\Services\Payment\BillplzGateway;
use App\Services\Payment\Payments;
use App\Services\RateLimiter;
use App\Services\ReceiptService;

final class OrderController extends Controller
{
    use HandlesReceipts;

    public function index(Request $request): void
    {
        $orders = Database::query('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 100', [Auth::user()['id']])->fetchAll();
        $this->view('member/orders/index', ['title' => 'My orders', 'orders' => $orders], 'portal');
    }

    public function show(Request $request, string $orderNo): void
    {
        $order = $this->mine($orderNo);
        header('Cache-Control: no-store');
        $this->view('member/orders/show', [
            'title' => 'Order ' . $order['order_no'],
            'order' => $order,
            'items' => OrderService::items((int) $order['id']),
            'events' => OrderService::events((int) $order['id']),
            'cancelAction' => $order['status'] === 'pending' ? '/member/orders/' . $order['order_no'] . '/cancel' : null,
            'payAction' => $order['payment_method'] === 'billplz' && $order['payment_status'] === 'unpaid' && $order['status'] === 'pending' ? '/member/orders/' . $order['order_no'] . '/pay' : null,
            'payment' => Payments::find($order['payment_method']),
            'proofs' => ReceiptService::forOrder((int) $order['id']),
            'proofAction' => '/member/orders/' . $order['order_no'] . '/receipt',
            'proofFileBase' => '/member/orders/' . $order['order_no'] . '/receipt',
            'scripts' => ['receipt-form.js'],
            'vendor' => ['select2'],
        ], 'portal');
    }

    public function cancel(Request $request, string $orderNo): void
    {
        $order = $this->mine($orderNo);
        $error = OrderService::transition((int) $order['id'], 'cancelled', (int) Auth::user()['id'], 'Cancelled by customer', true);
        if ($error !== null) {
            $this->backWithErrors('/member/orders/' . $orderNo, ['form' => $error]);
        }
        $this->success('/member/orders/' . $orderNo, 'Your order has been cancelled.');
    }

    public function receipt(Request $request, string $orderNo): void
    {
        $order = $this->mine($orderNo);
        $this->handleReceiptUpload($request, $order, '/member/orders/' . $orderNo, (int) Auth::user()['id']);
    }

    public function receiptFile(Request $request, string $orderNo, string $id): void
    {
        $order = $this->mine($orderNo);
        ReceiptService::send(ReceiptService::find((int) $order['id'], (int) $id) ?? Response::abort(404));
    }

    public function pay(Request $request, string $orderNo): void
    {
        $order = $this->mine($orderNo);
        $back = '/member/orders/' . $orderNo;
        if (RateLimiter::tooMany('pay', $request->ip(), 20, 10)) {
            $this->backWithErrors($back, ['form' => 'Too many attempts. Please wait a few minutes.']);
        }
        RateLimiter::hit('pay', $request->ip());
        $r = BillplzGateway::start((int) $order['id']);
        isset($r['url']) ? redirect($r['url']) : $this->backWithErrors($back, ['form' => $r['error']]);
    }

    /** Ownership check: someone else's order number is indistinguishable from a missing one. */
    private function mine(string $orderNo): array
    {
        $order = OrderService::findByNo($orderNo);
        if (!$order || (int) $order['user_id'] !== (int) Auth::user()['id']) {
            Response::abort(404);
        }
        return $order;
    }
}
