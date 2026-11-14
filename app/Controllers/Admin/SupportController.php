<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\SupportService;

/** Order message threads for admins and superadmins: the waiting list, and a reply box per order. */
final class SupportController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/support', [
            'title' => 'Order messages',
            'waiting' => SupportService::waiting(),
        ], 'portal');
    }

    public function show(Request $request, string $orderId): void
    {
        $order = Database::query('SELECT id, order_no, customer_name, customer_email, status FROM orders WHERE id = ?', [(int) $orderId])->fetch() ?: Response::abort(404);
        $this->view('admin/support-thread', [
            'title' => 'Messages: ' . $order['order_no'],
            'order' => $order,
            'thread' => SupportService::thread((int) $order['id']),
        ], 'portal');
    }

    public function reply(Request $request, string $orderId): void
    {
        $exists = Database::query('SELECT COUNT(*) FROM orders WHERE id = ?', [(int) $orderId])->fetchColumn();
        if (!$exists) {
            Response::abort(404);
        }
        $error = SupportService::post((int) $orderId, 'staff', $request->str('body'), (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors('/admin/support/' . (int) $orderId, ['form' => $error]);
        }
        $this->success('/admin/support/' . (int) $orderId, 'Reply sent. The customer is emailed a link to their order.');
    }
}
