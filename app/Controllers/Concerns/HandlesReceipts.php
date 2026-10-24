<?php
declare(strict_types=1);

namespace App\Controllers\Concerns;

use App\Core\Request;
use App\Services\RateLimiter;
use App\Services\ReceiptService;

/** Sending a payment receipt from an order page (guest link or member portal). The caller has already proved the order is the visitor's. */
trait HandlesReceipts
{
    protected function handleReceiptUpload(Request $request, array $order, string $back, ?int $actorId): never
    {
        if (RateLimiter::tooMany('receipt', $request->ip(), 12, 15)) {
            $this->backWithErrors($back, ['form' => 'Too many uploads. Please wait a few minutes and try again.']);
        }
        RateLimiter::hit('receipt', $request->ip());
        if (!ReceiptService::canUpload($order)) {
            $this->backWithErrors($back, ['form' => 'A receipt cannot be added to this order any more.']);
        }
        $p = ReceiptService::prepare($request->body, $_FILES['receipt'] ?? null);
        if ($p['errors'] !== []) {
            $p['errors']['form'] = 'Please check the highlighted details and attach your receipt again.';
            $this->backWithErrors($back, $p['errors'], $request->body);
        }
        try {
            ReceiptService::commit((int) $order['id'], $p['clean'], $p['info'], $_FILES['receipt'], $actorId);
        } catch (\Throwable $e) {
            error_log('Receipt not saved for order ' . $order['order_no'] . ': ' . $e->getMessage());
            $this->backWithErrors($back, ['form' => 'We could not save your receipt. Please try again.']);
        }
        $this->success($back, 'Receipt sent. We will check it and confirm your payment.');
    }
}
