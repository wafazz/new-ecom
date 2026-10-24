<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Invoice;
use App\Services\OrderService;

/** The PDF invoice for a buyer: a member for their own orders, a guest with the order's secret link. */
final class InvoiceController extends Controller
{
    public function member(Request $request, string $orderNo): void
    {
        $order = OrderService::findByNo($orderNo);
        if (!$order || (int) $order['user_id'] !== (int) Auth::user()['id']) {
            Response::abort(404);
        }
        Invoice::send($order);
    }

    public function guest(Request $request, string $orderNo, string $token): void
    {
        $order = OrderService::findByNo($orderNo);
        if (!$order || $order['track_hash'] === null || !hash_equals($order['track_hash'], hash('sha256', $token))) {
            Response::abort(404);
        }
        Invoice::send($order);
    }
}
