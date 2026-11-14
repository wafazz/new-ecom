<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\ModuleBillingService;

/** Billplz -> us for module invoices. Authenticated by X-Signature (server-to-server, so no session or CSRF). */
final class BillingCallbackController extends Controller
{
    public function callback(Request $request): void
    {
        $ok = ModuleBillingService::billplzCallback($_POST);
        Response::json(['ok' => $ok], $ok ? 200 : 400);
    }

    /** Where Billplz sends the admin back after paying. The result comes from the callback, not from this page. */
    public function back(Request $request): void
    {
        $this->success('/admin/billing', 'Thanks. Once Billplz confirms the payment, the module is paid. This can take a minute.');
    }
}
