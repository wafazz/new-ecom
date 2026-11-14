<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\PriceBreakService;

/** Quantity price breaks for admins and superadmins: choose a product and its breaks. */
final class PriceBreakController extends Controller
{
    public function index(Request $request): void
    {
        $pid = (int) $request->str('product', '0');
        $this->view('admin/price-breaks', [
            'title' => 'Quantity prices',
            'products' => Database::query("SELECT id, name FROM products WHERE deleted_at IS NULL AND status = 'active' AND product_type <> 'digital' ORDER BY name")->fetchAll(),
            'pid' => $pid,
            'breaks' => $pid > 0 ? PriceBreakService::breaksFor($pid) : [],
        ], 'portal');
    }

    public function save(Request $request): void
    {
        $pid = (int) $request->input('product_id', 0);
        $rows = [];
        foreach ((array) $request->input('min_qty', []) as $i => $min) {
            $rows[] = ['min_qty' => (string) $min, 'unit_price' => (string) (($request->input('unit_price', [])[$i] ?? ''))];
        }
        $error = PriceBreakService::save($pid, $rows, (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors('/admin/price-breaks?product=' . $pid, ['form' => $error]);
        }
        $this->success('/admin/price-breaks?product=' . $pid, 'Quantity prices saved.');
    }
}
