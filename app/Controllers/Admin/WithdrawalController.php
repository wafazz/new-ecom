<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\AffiliateWithdrawalService;

/** Affiliate withdrawals for HQ: the pending list, marking a transfer as paid, and rejecting a request. HQ (admin) only. */
final class WithdrawalController extends Controller
{
    private const STATUSES = ['pending', 'paid', 'rejected'];

    public function index(Request $request): void
    {
        header('Cache-Control: no-store');
        $status = in_array($request->str('status'), self::STATUSES, true) ? $request->str('status') : 'pending';
        $this->view('admin/withdrawals', [
            'title' => 'Withdrawals',
            'status' => $status,
            'rows' => AffiliateWithdrawalService::listFor($status),
        ], 'portal');
    }

    public function paid(Request $request, string $id): void
    {
        $error = AffiliateWithdrawalService::markPaid((int) $id, (int) Auth::user()['id'], $request->str('reference'));
        if ($error !== null) {
            $this->backWithErrors('/admin/withdrawals', ['form' => $error]);
        }
        $this->success('/admin/withdrawals', 'Marked as paid. The member can see the payout on their affiliate page.');
    }

    public function reject(Request $request, string $id): void
    {
        $error = AffiliateWithdrawalService::reject((int) $id, (int) Auth::user()['id'], $request->str('note'));
        if ($error !== null) {
            $this->backWithErrors('/admin/withdrawals', ['form' => $error]);
        }
        $this->success('/admin/withdrawals', 'Withdrawal rejected. The amount is back in the member\'s balance.');
    }
}
