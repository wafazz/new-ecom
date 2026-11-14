<?php
declare(strict_types=1);

namespace App\Controllers\Superadmin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ModuleBillingService;

/** Module invoices for the superadmin: check transfer receipts, approve or reject them. */
final class InvoiceController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('superadmin/invoices', [
            'title' => 'Module invoices',
            'waiting' => ModuleBillingService::waiting(),
            'recent' => Database::query('SELECT i.*, u.name requester FROM module_invoices i LEFT JOIN users u ON u.id = i.requested_by ORDER BY i.id DESC LIMIT 30')->fetchAll(),
        ], 'portal');
    }

    /** The uploaded receipt, served only to the superadmin. Stored outside public/. */
    public function receipt(Request $request, string $id): void
    {
        $inv = Database::query('SELECT receipt_path FROM module_invoices WHERE id = ?', [(int) $id])->fetch();
        if (!$inv || !is_string($inv['receipt_path']) || preg_match('/^[a-f0-9]{32}\.(jpg|png|pdf)$/', $inv['receipt_path']) !== 1) {
            Response::abort(404);
        }
        $file = BASE_PATH . '/storage/billing/' . $inv['receipt_path'];
        if (!is_file($file)) {
            Response::abort(404);
        }
        $type = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][pathinfo($file, PATHINFO_EXTENSION)];
        header('Content-Type: ' . $type);
        header('Content-Disposition: inline; filename="receipt-' . (int) $id . '.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    public function approve(Request $request, string $id): void
    {
        $error = ModuleBillingService::review((int) $id, true, '', (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors('/superadmin/invoices', ['form' => $error]);
        }
        $this->success('/superadmin/invoices', 'Receipt approved. The module is paid up.');
    }

    public function reject(Request $request, string $id): void
    {
        $error = ModuleBillingService::review((int) $id, false, $request->str('note'), (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors('/superadmin/invoices', ['form' => $error]);
        }
        $this->success('/superadmin/invoices', 'Receipt not accepted. The shop can upload another.');
    }
}
