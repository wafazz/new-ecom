<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\SalesReportService;
use App\Services\TaxService;
use App\Core\Auth;

/** SST rates (per category, and per product as an override) and the monthly SST report for admins and superadmins. */
final class TaxController extends Controller
{
    public function index(Request $request): void
    {
        $w = SalesReportService::window($request->str('from'), $request->str('to'), 'month');
        $this->view('admin/tax', [
            'title' => 'SST',
            'w' => $w,
            'categories' => Database::query('SELECT id, name, tax_rate FROM categories ORDER BY name')->fetchAll(),
            'products' => Database::query("SELECT id, name, tax_rate FROM products WHERE deleted_at IS NULL ORDER BY name LIMIT 500")->fetchAll(),
            'report' => TaxService::report($w['from'], $w['to']),
        ], 'portal');
    }

    public function category(Request $request, string $id): void
    {
        $rate = $this->rate($request);
        Database::query('UPDATE categories SET tax_rate = ? WHERE id = ?', [$rate, (int) $id]);
        AuditService::log((int) Auth::user()['id'], 'sst.category', "category {$id}: " . ($rate === null ? 'inherits' : $rate . '%'));
        $this->success('/admin/tax', 'SST rate saved for the category.');
    }

    public function product(Request $request): void
    {
        $id = (int) $request->input('product', 0);
        $exists = Database::query('SELECT COUNT(*) FROM products WHERE id = ? AND deleted_at IS NULL', [$id])->fetchColumn();
        if (!$exists) {
            Response::abort(404);
        }
        $rate = $this->rate($request);
        Database::query('UPDATE products SET tax_rate = ? WHERE id = ?', [$rate, $id]);
        AuditService::log((int) Auth::user()['id'], 'sst.product', "product {$id}: " . ($rate === null ? 'uses its category' : $rate . '%'));
        $this->success('/admin/tax', 'SST rate saved for the product.');
    }

    public function export(Request $request): void
    {
        $w = SalesReportService::window($request->str('from'), $request->str('to'), 'month');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sst-' . $w['from'] . '-to-' . $w['to'] . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['month', 'orders', 'sales_rm_incl_sst', 'included_sst_rm']);
        foreach (TaxService::report($w['from'], $w['to']) as $r) {
            fputcsv($out, [$r['period'], $r['orders'], number_format($r['sales'], 2, '.', ''), number_format($r['sst'], 2, '.', '')]);
        }
        fclose($out);
        exit;
    }

    /** A percentage from 0 to 100, or null for "inherit" (blank). */
    private function rate(Request $request): ?string
    {
        $raw = trim($request->str('rate'));
        if ($raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
            $this->backWithErrors('/admin/tax', ['form' => 'Enter a rate from 0 to 100, or leave it blank to inherit.']);
        }
        return number_format((float) $raw, 2, '.', '');
    }
}
