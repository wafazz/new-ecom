<?php
declare(strict_types=1);

namespace App\Controllers\Member;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\AffiliateService;
use App\Services\AffiliateWithdrawalService;
use App\Services\Banks;
use App\Services\RateLimiter;

final class AffiliateController extends Controller
{
    public function show(Request $request): void
    {
        $id = (int) Auth::user()['id'];
        $code = AffiliateService::ensureCode($id);
        // No order numbers or customer data here: members only see their own earnings.
        $this->view('member/affiliate', [
            'title' => 'Affiliate',
            'code' => $code,
            'link' => AffiliateService::link($code),
            'stats' => AffiliateService::stats($id),
            'days' => AffiliateService::cookieDays(),
            'commissions' => Database::query('SELECT amount, status, created_at FROM affiliate_commissions WHERE affiliate_id = ? ORDER BY id DESC LIMIT 50', [$id])->fetchAll(),
            'payouts' => Database::query('SELECT amount, reference, paid_at FROM affiliate_payouts WHERE affiliate_id = ? ORDER BY id DESC LIMIT 20', [$id])->fetchAll(),
            'balance' => AffiliateWithdrawalService::balance($id),
            'withdrawals' => Database::query('SELECT amount, fee, net, status, bank_name, reference, note, requested_at, processed_at FROM affiliate_withdrawals WHERE affiliate_id = ? ORDER BY id DESC LIMIT 20', [$id])->fetchAll(),
            'banks' => Banks::groups(),
            'products' => Database::query("SELECT name, slug FROM products WHERE deleted_at IS NULL AND status = 'active' ORDER BY name LIMIT 300")->fetchAll(),
            'scripts' => ['copy-link.js'],
        ], 'portal');
    }

    /** Ask HQ to pay out an amount of the approved commission. The account number is never shown back. */
    public function withdraw(Request $request): void
    {
        $id = (int) Auth::user()['id'];
        $back = '/member/affiliate';
        if (RateLimiter::tooMany('withdraw', 'u' . $id, 10, 60)) {
            $this->backWithErrors($back, ['form' => 'Too many requests. Please wait a while and try again.']);
        }
        RateLimiter::hit('withdraw', 'u' . $id);

        $r = AffiliateWithdrawalService::request($id, $request->str('amount'), $request->str('bank_name'), $request->str('account_holder'), $request->str('account_no'));
        if ($r['error'] !== null) {
            $this->backWithErrors($back, ['form' => $r['error']], [
                'amount' => $request->str('amount'),
                'bank_name' => $request->str('bank_name'),
                'account_holder' => $request->str('account_holder'),
            ]);
        }
        $this->success($back, 'Withdrawal requested. HQ will transfer it to your bank account. The RM' . AffiliateWithdrawalService::FEE . ' fee is taken from the amount.');
    }
}
