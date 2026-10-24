<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\BillplzSettings;
use App\Services\OrderService;
use App\Services\Payment\BillplzGateway as G;
use App\Services\PricingService;

/** Billplz -> us. Authenticated by X-Signature only (no session, no CSRF token: it's a server-to-server webhook). */
final class BillplzController extends Controller
{
    /** POST /payments/billplz/callback */
    public function callback(Request $request): void
    {
        $creds = BillplzSettings::credentials();
        if ($creds === null) {
            http_response_code(503);
            exit('not configured');
        }
        if (!G::callbackValid($_POST, $creds['x_signature_key'])) {
            G::log('callback rejected: bad signature from ' . $request->ip());
            http_response_code(403);
            exit('invalid signature');
        }

        $order = G::orderByBill((string) ($_POST['id'] ?? ''));
        // Valid signature but nothing for us to do: answer 200 so Billplz stops retrying.
        if ($order === null || ($_POST['collection_id'] ?? '') !== $creds['collection_id']) {
            G::log('callback ignored: unknown bill or collection');
            exit('OK');
        }
        if (($_POST['paid'] ?? '') === 'true') {
            $total = PricingService::cents((string) $order['total']);
            if ((int) ($_POST['amount'] ?? -1) !== $total || (int) ($_POST['paid_amount'] ?? -1) < $total) {
                OrderService::note((int) $order['id'], 'payment_mismatch', 'Billplz reported a payment whose amount does not match the order - check manually');
                G::log("callback amount mismatch for {$order['order_no']}");
                exit('OK');
            }
            OrderService::recordPayment((int) $order['id'], 'Paid via Billplz (bill ' . $order['billplz_bill_id'] . ')');
        }
        header('Content-Type: text/plain; charset=utf-8');
        exit('OK');
    }

    /** GET /payments/billplz/return — the customer comes back from Billplz. */
    public function back(Request $request): void
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        $creds = BillplzSettings::credentials();
        $b = $_GET['billplz'] ?? null;
        if ($creds === null || !G::redirectValid($b, $creds['x_signature_key'])) {
            Response::abort(404);
        }
        $order = G::orderByBill((string) ($b['id'] ?? '')) ?? Response::abort(404);

        $state = $order['payment_status'];
        if ($state === 'unpaid' && ($b['paid'] ?? '') === 'true') {
            // The signed redirect says paid: confirm with Billplz itself before marking the order.
            $bill = G::fetchBill((string) $order['billplz_bill_id'], $creds);
            if ($bill !== null && ($bill['state'] ?? '') === 'paid' && (int) ($bill['amount'] ?? -1) === PricingService::cents((string) $order['total'])) {
                OrderService::recordPayment((int) $order['id'], 'Paid via Billplz (bill ' . $order['billplz_bill_id'] . ')');
                $state = 'paid';
            }
        }

        if ($order['user_id'] !== null) {
            Session::flash('success', $state === 'paid' ? 'Payment received. Thank you!' : 'We have not received your payment yet. If you were charged it will show here shortly.');
            redirect('/member/orders/' . $order['order_no']);
        }
        $this->view('shop/payment_return', ['title' => 'Payment', 'orderNo' => $order['order_no'], 'paid' => $state === 'paid']);
    }
}
