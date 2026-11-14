<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Services\SalesReportService;

/** Sales reports for admins and superadmins: a date range, by day or month, with a CSV download. */
final class ReportController extends Controller
{
    public function index(Request $request): void
    {
        $w = SalesReportService::window($request->str('from'), $request->str('to'), $request->str('grain'));
        $this->view('admin/reports', [
            'title' => 'Sales reports',
            'w' => $w,
            'summary' => SalesReportService::summary($w['from'], $w['to']),
            'periods' => SalesReportService::byPeriod($w['from'], $w['to'], $w['grain']),
            'top' => SalesReportService::topProducts($w['from'], $w['to']),
            'payments' => SalesReportService::byPayment($w['from'], $w['to']),
        ], 'portal');
    }

    public function export(Request $request): void
    {
        $w = SalesReportService::window($request->str('from'), $request->str('to'), $request->str('grain'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sales-' . $w['from'] . '-to-' . $w['to'] . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['period', 'orders', 'sales_rm']);
        foreach (SalesReportService::byPeriod($w['from'], $w['to'], $w['grain']) as $p) {
            fputcsv($out, [$p['period'], $p['orders'], number_format($p['sales'], 2, '.', '')]);
        }
        fclose($out);
        exit;
    }
}
