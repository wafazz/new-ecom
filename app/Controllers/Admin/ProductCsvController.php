<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Services\ProductCsvService;

/**
 * Prices and stock by SKU, as a spreadsheet: export the catalogue, check an uploaded file row by row, then apply.
 * The checked file is kept in the session so the apply step checks it again against the live data.
 */
final class ProductCsvController extends Controller
{
    private const SESSION_KEY = 'product_csv';

    public function index(Request $request): void
    {
        $this->render(Session::get(self::SESSION_KEY) !== null ? ProductCsvService::check((string) Session::get(self::SESSION_KEY)) : null);
    }

    public function export(Request $request): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="products-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        foreach (ProductCsvService::exportRows() as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    /** Upload and check. Nothing is saved yet. */
    public function check(Request $request): void
    {
        $f = $_FILES['csv'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            $this->backWithErrors('/admin/products/csv', ['form' => 'Choose a CSV file to upload.']);
        }
        if (strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $this->backWithErrors('/admin/products/csv', ['form' => 'The file must be a .csv file. Save your spreadsheet as CSV first.']);
        }
        $csv = (string) file_get_contents((string) $f['tmp_name']);
        $checked = ProductCsvService::check($csv);
        if ($checked['fatal'] !== null) {
            $this->backWithErrors('/admin/products/csv', ['form' => $checked['fatal']]);
        }
        Session::set(self::SESSION_KEY, $csv);
        $this->render($checked);
    }

    public function apply(Request $request): void
    {
        $csv = Session::get(self::SESSION_KEY);
        if (!is_string($csv)) {
            $this->backWithErrors('/admin/products/csv', ['form' => 'Upload the file again, then apply it.']);
        }
        try {
            $r = ProductCsvService::apply($csv, (int) Auth::user()['id']);
        } catch (\DomainException $e) {
            $this->backWithErrors('/admin/products/csv', ['form' => $e->getMessage()]);
        }
        Session::forget(self::SESSION_KEY);
        $this->success('/admin/products/csv', "Imported: {$r['prices']} price(s) and {$r['stock']} stock line(s). Rows with errors were skipped.");
    }

    public function cancel(Request $request): void
    {
        Session::forget(self::SESSION_KEY);
        $this->success('/admin/products/csv', 'Import cancelled. Nothing was changed.');
    }

    private function render(?array $checked): void
    {
        $this->view('admin/product-csv', [
            'title' => 'Import and export',
            'checked' => $checked,
            'headers' => ProductCsvService::HEADERS,
        ], 'portal');
    }
}
