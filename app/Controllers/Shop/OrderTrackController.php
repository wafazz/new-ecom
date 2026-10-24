<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Controllers\Concerns\HandlesReceipts;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OrderService;
use App\Services\Payment\BillplzGateway;
use App\Services\Payment\Payments;
use App\Services\RateLimiter;
use App\Services\ReceiptService;

/** Guest order access: by secret link (order_no + token) or by order_no + email. */
final class OrderTrackController extends Controller
{
    use HandlesReceipts;

    public function show(Request $request, string $orderNo, string $token): void
    {
        $order = $this->byToken($orderNo, $token);
        $this->render($order, "/order/{$orderNo}/{$token}");
    }

    public function cancel(Request $request, string $orderNo, string $token): void
    {
        $order = $this->byToken($orderNo, $token);
        $error = OrderService::transition((int) $order['id'], 'cancelled', null, 'Cancelled by customer', true);
        if ($error !== null) {
            $this->backWithErrors("/order/{$orderNo}/{$token}", ['form' => $error]);
        }
        $this->success("/order/{$orderNo}/{$token}", 'Your order has been cancelled.');
    }

    public function receipt(Request $request, string $orderNo, string $token): void
    {
        $order = $this->byToken($orderNo, $token);
        $this->handleReceiptUpload($request, $order, "/order/{$orderNo}/{$token}", null);
    }

    public function receiptFile(Request $request, string $orderNo, string $token, string $id): void
    {
        $order = $this->byToken($orderNo, $token);
        ReceiptService::send(ReceiptService::find((int) $order['id'], (int) $id) ?? Response::abort(404));
    }

    public function pay(Request $request, string $orderNo, string $token): void
    {
        $order = $this->byToken($orderNo, $token);
        $back = "/order/{$orderNo}/{$token}";
        if (RateLimiter::tooMany('pay', $request->ip(), 20, 10)) {
            $this->backWithErrors($back, ['form' => 'Too many attempts. Please wait a few minutes.']);
        }
        RateLimiter::hit('pay', $request->ip());
        $r = BillplzGateway::start((int) $order['id']);
        isset($r['url']) ? redirect($r['url']) : $this->backWithErrors($back, ['form' => $r['error']]);
    }

    public function form(Request $request): void
    {
        $this->view('shop/track', ['title' => 'Track your order']);
    }

    public function lookup(Request $request): void
    {
        if (RateLimiter::tooMany('track', $request->ip(), 10, 15)) {
            $this->backWithErrors('/track', ['form' => 'Too many attempts. Please try again in a few minutes.']);
        }
        RateLimiter::hit('track', $request->ip());

        $orderNo = strtoupper(trim($request->str('order_no')));
        $email = strtolower(trim($request->str('email')));
        $order = OrderService::findByNo($orderNo);

        // Same answer whether the number or the email was wrong.
        if (!$order || !hash_equals(strtolower($order['customer_email']), $email)) {
            $this->backWithErrors('/track', ['form' => 'No order found for that order number and email.'], ['order_no' => $orderNo]);
        }
        $this->render($order, null);
    }

    private static function payable(array $o): bool
    {
        return $o['payment_method'] === 'billplz' && $o['payment_status'] === 'unpaid' && $o['status'] === 'pending';
    }

    private function byToken(string $orderNo, string $token): array
    {
        $order = OrderService::findByNo($orderNo);
        if (!$order || $order['track_hash'] === null || !hash_equals($order['track_hash'], hash('sha256', $token))) {
            Response::abort(404);
        }
        return $order;
    }

    private function render(array $order, ?string $cancelBase): void
    {
        // Pages that can carry a secret in the URL must not leak it or get cached/indexed.
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        $this->view('shop/order', [
            'title' => 'Order ' . $order['order_no'],
            'order' => $order,
            'items' => OrderService::items((int) $order['id']),
            'events' => OrderService::events((int) $order['id']),
            'cancelAction' => $order['status'] === 'pending' && $cancelBase !== null ? $cancelBase . '/cancel' : null,
            'payAction' => $cancelBase !== null && self::payable($order) ? $cancelBase . '/pay' : null,
            'invoiceAction' => $cancelBase !== null ? $cancelBase . '/invoice' : null,
            'payment' => Payments::find($order['payment_method']),
            'proofs' => ReceiptService::forOrder((int) $order['id']),
            'proofAction' => $cancelBase !== null ? $cancelBase . '/receipt' : null,
            'proofFileBase' => $cancelBase !== null ? $cancelBase . '/receipt' : null,
            'scripts' => ['receipt-form.js'],
            'vendor' => ['select2'],
        ]);
    }
}
