<?php
declare(strict_types=1);

namespace App\Controllers\Member;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\AffiliateService;

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
        ], 'portal');
    }
}
