<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\BundleService;

/** Bundles for admins and superadmins: choose the bundle product and the items in it. */
final class BundleController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/bundles', [
            'title' => 'Bundles',
            'bundles' => BundleService::listing(),
            'products' => Database::query("SELECT p.id, p.name, p.product_type FROM products p WHERE p.deleted_at IS NULL AND p.status = 'active' AND p.product_type <> 'digital' AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL) ORDER BY p.name")->fetchAll(),
        ], 'portal');
    }

    public function save(Request $request): void
    {
        $bundleId = (int) $request->input('bundle_id', 0);
        $parts = [];
        foreach ((array) $request->input('part', []) as $i => $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $parts[] = ['product_id' => $pid, 'qty' => (int) (($request->input('qty', [])[$i] ?? 1))];
            }
        }
        $error = BundleService::setParts($bundleId, $parts, (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors('/admin/bundles', ['form' => $error]);
        }
        $this->success('/admin/bundles', 'Bundle saved. Its stock now follows its items.');
    }
}
