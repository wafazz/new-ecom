<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AffiliateService;

final class AffiliateController extends Controller
{
    private const PER_PAGE = 25;
    private const STATUSES = ['pending', 'approved', 'paid', 'rejected'];

    public function index(Request $request): void
    {
        $rows = Database::query(
            "SELECT u.id, u.name, u.email, u.affiliate_code, u.status,
                    (SELECT COUNT(*) FROM affiliate_clicks c WHERE c.affiliate_id = u.id) AS clicks,
                    (SELECT COUNT(*) FROM orders o WHERE o.affiliate_id = u.id) AS orders,
                    COALESCE((SELECT SUM(amount) FROM affiliate_commissions m WHERE m.affiliate_id = u.id AND m.status = 'pending'), 0) AS pending,
                    COALESCE((SELECT SUM(amount) FROM affiliate_commissions m WHERE m.affiliate_id = u.id AND m.status = 'approved'), 0) AS approved,
                    COALESCE((SELECT SUM(amount) FROM affiliate_commissions m WHERE m.affiliate_id = u.id AND m.status = 'paid'), 0) AS paid
             FROM users u WHERE u.affiliate_code IS NOT NULL ORDER BY u.id DESC LIMIT 200"
        )->fetchAll();
        $this->view('admin/affiliates/index', ['title' => 'Affiliates', 'affiliates' => $rows], 'portal');
    }

    public function show(Request $request, string $id): void
    {
        $aff = $this->affiliate($id);
        $this->view('admin/affiliates/show', [
            'title' => 'Affiliate ' . $aff['name'],
            'aff' => $aff,
            'stats' => AffiliateService::stats((int) $aff['id']),
            'commissions' => Database::query(
                'SELECT m.*, o.order_no FROM affiliate_commissions m JOIN orders o ON o.id = m.order_id WHERE m.affiliate_id = ? ORDER BY m.id DESC LIMIT 100',
                [$aff['id']]
            )->fetchAll(),
            'payouts' => Database::query(
                'SELECT p.*, u.name AS by_name FROM affiliate_payouts p LEFT JOIN users u ON u.id = p.paid_by WHERE p.affiliate_id = ? ORDER BY p.id DESC LIMIT 50',
                [$aff['id']]
            )->fetchAll(),
        ], 'portal');
    }

    public function commissions(Request $request): void
    {
        $status = $request->str('status');
        $page = max(1, (int) $request->input('page', 1));
        $where = in_array($status, self::STATUSES, true) ? 'WHERE m.status = ?' : '';
        $args = $where === '' ? [] : [$status];
        $limit = self::PER_PAGE;
        $offset = ($page - 1) * $limit;

        $total = (int) Database::query("SELECT COUNT(*) FROM affiliate_commissions m {$where}", $args)->fetchColumn();
        $rows = Database::query(
            "SELECT m.*, o.order_no, o.status AS order_status, u.name AS aff_name
             FROM affiliate_commissions m JOIN orders o ON o.id = m.order_id JOIN users u ON u.id = m.affiliate_id
             {$where} ORDER BY m.id DESC LIMIT {$limit} OFFSET {$offset}",
            $args
        )->fetchAll();

        $this->view('admin/affiliates/commissions', [
            'title' => 'Commissions',
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $limit)),
            'status' => in_array($status, self::STATUSES, true) ? $status : '',
            'statuses' => self::STATUSES,
        ], 'portal');
    }

    public function approve(Request $request, string $id): void
    {
        $this->decide($request, $id, 'approved');
    }

    public function reject(Request $request, string $id): void
    {
        $this->decide($request, $id, 'rejected');
    }

    private function decide(Request $request, string $id, string $to): void
    {
        $back = $request->str('back') === 'affiliate' ? '/admin/affiliates/' . (int) $request->str('affiliate_id') : '/admin/commissions';
        $error = AffiliateService::decide((int) $id, $to, (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors($back, ['form' => $error]);
        }
        $this->success($back, "Commission {$to}.");
    }

    private function affiliate(string $id): array
    {
        return Database::query('SELECT id, name, email, affiliate_code, status FROM users WHERE id = ? AND affiliate_code IS NOT NULL', [(int) $id])->fetch() ?: Response::abort(404);
    }
}
