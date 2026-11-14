<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\MemberPriceService;

/** Member price groups for admins and superadmins: groups, members in them, and their prices. */
final class MemberPriceController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/member-prices', [
            'title' => 'Member prices',
            'groups' => MemberPriceService::groups(),
            'products' => Database::query("SELECT id, name FROM products WHERE deleted_at IS NULL AND status = 'active' AND product_type <> 'digital' ORDER BY name")->fetchAll(),
            'prices' => Database::query('SELECT gp.group_id, gp.product_id, gp.unit_price, p.name, g.name grp FROM member_group_prices gp JOIN products p ON p.id = gp.product_id JOIN member_groups g ON g.id = gp.group_id ORDER BY g.name, p.name')->fetchAll(),
        ], 'portal');
    }

    public function group(Request $request): void
    {
        $error = MemberPriceService::createGroup($request->str('name'), (int) Auth::user()['id']);
        $this->finish($error, 'Group created.');
    }

    public function assign(Request $request): void
    {
        $group = (int) $request->input('group_id', 0);
        $error = MemberPriceService::assign($request->str('email'), $group > 0 ? $group : null, (int) Auth::user()['id']);
        $this->finish($error, 'Member updated.');
    }

    public function price(Request $request): void
    {
        $error = MemberPriceService::setPrice((int) $request->input('group_id', 0), (int) $request->input('product_id', 0), trim($request->str('price')), (int) Auth::user()['id']);
        $this->finish($error, 'Group price saved.');
    }

    private function finish(?string $error, string $ok): void
    {
        if ($error !== null) {
            $this->backWithErrors('/admin/member-prices', ['form' => $error]);
        }
        $this->success('/admin/member-prices', $ok);
    }
}
