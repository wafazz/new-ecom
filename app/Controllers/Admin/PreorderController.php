<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\PreorderService;

/** Pre-orders for admins and superadmins: open a pre-order, and record when the stock arrives. */
final class PreorderController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/preorders', [
            'title' => 'Pre-orders',
            'open' => PreorderService::openList(),
            'products' => Database::query("SELECT id, name FROM products WHERE deleted_at IS NULL AND status = 'active' AND product_type <> 'digital' ORDER BY name")->fetchAll(),
        ], 'portal');
    }

    public function open(Request $request): void
    {
        $error = PreorderService::open((int) $request->input('product_id', 0), $request->str('expected'), (int) $request->input('limit', 0), (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors('/admin/preorders', ['form' => $error]);
        }
        $this->success('/admin/preorders', 'Pre-order open.');
    }

    public function arrive(Request $request): void
    {
        $error = PreorderService::arrive((int) $request->input('product_id', 0), (int) $request->input('arrived', 0), (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors('/admin/preorders', ['form' => $error]);
        }
        $this->success('/admin/preorders', 'Stock arrived. Stock now counts the units left after the pre-orders.');
    }
}
