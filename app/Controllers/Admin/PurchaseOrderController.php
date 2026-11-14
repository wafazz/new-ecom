<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\PurchaseOrderService;

/** Suppliers and purchase orders for admins and superadmins. Stock goes up only when a delivery is recorded. */
final class PurchaseOrderController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/purchase-orders', [
            'title' => 'Purchase orders',
            'suppliers' => Database::query('SELECT id, name, lead_days FROM suppliers ORDER BY name')->fetchAll(),
            'products' => Database::query("SELECT id, name, stock FROM products WHERE deleted_at IS NULL AND status = 'active' AND product_type <> 'digital' ORDER BY name")->fetchAll(),
            'suggestions' => PurchaseOrderService::suggestions(),
            'orders' => PurchaseOrderService::listing(),
        ], 'portal');
    }

    public function supplier(Request $request): void
    {
        $error = PurchaseOrderService::createSupplier($request->str('name'), $request->str('contact'), $request->str('lead_days'), (int) Auth::user()['id']);
        $this->finish($error, '/admin/purchase-orders', 'Supplier saved.');
    }

    public function create(Request $request): void
    {
        $lines = [];
        foreach ((array) $request->input('product', []) as $i => $pid) {
            $lines[] = [
                'product_id' => $pid,
                'qty' => ($request->input('qty', [])[$i] ?? ''),
                'unit_cost' => ($request->input('cost', [])[$i] ?? ''),
            ];
        }
        $result = PurchaseOrderService::createOrder((int) $request->input('supplier_id', 0), $lines, (int) Auth::user()['id']);
        if (is_string($result)) {
            $this->backWithErrors('/admin/purchase-orders', ['form' => $result]);
        }
        $this->success('/admin/purchase-orders/' . $result, 'Purchase order draft made. Send it when it has gone to the supplier.');
    }

    public function show(Request $request, string $id): void
    {
        $po = Database::query('SELECT po.*, s.name supplier FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.id = ?', [(int) $id])->fetch() ?: Response::abort(404);
        $this->view('admin/purchase-order', [
            'title' => 'Purchase order #' . $po['id'],
            'po' => $po,
            'items' => Database::query('SELECT i.*, p.name, p.sku FROM purchase_order_items i JOIN products p ON p.id = i.product_id WHERE i.po_id = ? ORDER BY i.id', [(int) $id])->fetchAll(),
        ], 'portal');
    }

    public function send(Request $request, string $id): void
    {
        $this->finish(PurchaseOrderService::send((int) $id, (int) Auth::user()['id']), '/admin/purchase-orders/' . (int) $id, 'Purchase order sent.');
    }

    public function receive(Request $request, string $id): void
    {
        $received = (array) $request->input('received', []);
        try {
            $error = PurchaseOrderService::receive((int) $id, $received, (int) Auth::user()['id']);
        } catch (\DomainException $e) {
            $error = $e->getMessage();
        }
        $this->finish($error, '/admin/purchase-orders/' . (int) $id, 'Delivery recorded. Stock has gone up for the lines received.');
    }

    private function finish(?string $error, string $path, string $ok): void
    {
        if ($error !== null) {
            $this->backWithErrors($path, ['form' => $error]);
        }
        $this->success($path, $ok);
    }
}
