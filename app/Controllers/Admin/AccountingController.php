<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Services\AccountingExportService;
use App\Services\SalesReportService;

/** Accounting journal for admins and superadmins: check the totals, then download the CSV. */
final class AccountingController extends Controller
{
    public function index(Request $request): void
    {
        $w = SalesReportService::window($request->str('from'), $request->str('to'), 'month');
        $lines = AccountingExportService::journal($w['from'], $w['to']);
        $this->view('admin/accounting', [
            'title' => 'Accounting export',
            'w' => $w,
            'lines' => array_slice($lines, 0, 200),
            'lineCount' => count($lines),
            'totals' => AccountingExportService::totals($lines),
            'accounts' => AccountingExportService::ACCOUNTS,
        ], 'portal');
    }

    public function export(Request $request): void
    {
        $w = SalesReportService::window($request->str('from'), $request->str('to'), 'month');
        $error = null;
        $csv = AccountingExportService::csv($w['from'], $w['to'], $error);
        if ($csv === null) {
            $this->backWithErrors('/admin/accounting?from=' . $w['from'] . '&to=' . $w['to'], ['form' => (string) $error]);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="journal-' . $w['from'] . '-to-' . $w['to'] . '.csv"');
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }
}
