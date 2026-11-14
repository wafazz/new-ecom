<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\ReturnService;

/** Return and refund requests for admins and superadmins. */
final class ReturnController extends Controller
{
    public function index(Request $request): void
    {
        $status = $request->str('status');
        $this->view('admin/returns', [
            'title' => 'Returns and refunds',
            'status' => $status,
            'rows' => ReturnService::listing($status),
        ], 'portal');
    }

    public function decide(Request $request, string $id, string $action): void
    {
        $error = ReturnService::decide((int) $id, $action, (int) Auth::user()['id'], $request->str('note'), $request->str('amount'), $request->str('method') ?: 'cash');
        if ($error !== null) {
            $this->backWithErrors('/admin/returns', ['form' => $error]);
        }
        $done = ['approve' => 'Return approved.', 'reject' => 'Return rejected. The customer can see your reason.', 'receive' => 'Marked received. Stock has been put back.', 'refund' => 'Refund recorded.'];
        $this->success('/admin/returns', $done[$action] ?? 'Saved.');
    }
}
