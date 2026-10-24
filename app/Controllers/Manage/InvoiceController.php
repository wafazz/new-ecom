<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Invoice;
use App\Services\OrderService;

/** The PDF invoice for an order, for admin and staff (both need orders.view). */
final class InvoiceController extends Controller
{
    public function show(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        Invoice::send($order);
    }
}
