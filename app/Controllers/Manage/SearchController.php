<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/** Command-palette search for the admin and staff portals. Each result group needs the matching permission. */
final class SearchController extends ManageController
{
    public function index(Request $request): void
    {
        header('Cache-Control: no-store');
        $q = trim($request->str('q'));
        $base = $this->base();
        $out = ['orders' => [], 'products' => []];
        if (mb_strlen($q) < 2 || mb_strlen($q) > 60) {
            Response::json($out);
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        if (Auth::can('orders.view')) {
            foreach (Database::query(
                'SELECT id, order_no, customer_name, customer_email, status, total FROM orders WHERE order_no LIKE ? OR customer_email LIKE ? OR customer_name LIKE ? ORDER BY id DESC LIMIT 5',
                [$like, $like, $like]
            )->fetchAll() as $r) {
                $out['orders'][] = ['label' => $r['order_no'], 'sub' => $r['customer_name'] . ' · ' . $r['status'] . ' · ' . money($r['total']), 'href' => $base . '/orders/' . $r['id']];
            }
        }
        if (Auth::can('products.manage')) {
            foreach (Database::query(
                'SELECT id, name, sku, status FROM products WHERE deleted_at IS NULL AND (name LIKE ? OR sku LIKE ?) ORDER BY id DESC LIMIT 5',
                [$like, $like]
            )->fetchAll() as $r) {
                $out['products'][] = ['label' => $r['name'], 'sub' => trim(($r['sku'] ?? '') . ' ' . $r['status']), 'href' => $base . '/products/' . $r['id'] . '/edit'];
            }
        }
        Response::json($out);
    }
}
