<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\ModuleBillingService;
use App\Services\ModulePricing;
use App\Services\Modules;
use App\Services\PlatformSettings;
use App\Services\Payment\BillplzGateway;

/** Module invoices for the shop's admins: see what is paid, pay by Billplz, or upload a bank transfer receipt. */
final class BillingController extends Controller
{
    public function index(Request $request): void
    {
        $rows = [];
        foreach (Modules::LIST as $key => [$label]) {
            $plans = ModulePricing::plans($key);
            if ($plans === []) {
                continue;
            }
            $quotes = [];
            foreach (array_keys($plans) as $period) {
                $quotes[$period] = ModulePricing::quote($key, $period);
            }
            $open = \App\Core\Database::query("SELECT * FROM module_invoices WHERE module_key = ? AND status IN ('unpaid','pending_review') ORDER BY id DESC LIMIT 1", [$key])->fetch() ?: null;
            $rows[] = [
                'key' => $key, 'label' => $label, 'quotes' => $quotes,
                'until' => ModuleBillingService::paidUntil($key), 'subscribed' => ModuleBillingService::subscribedPeriod($key),
                'covered' => ModuleBillingService::covered($key), 'open' => $open,
                'switched_on' => Modules::switchedOn($key),
            ];
        }
        $this->view('admin/billing', [
            'title' => 'Module billing',
            'rows' => $rows,
            'bank' => PlatformSettings::bank(),
            'billplzReady' => PlatformSettings::billplzCredentials() !== null,
            'scripts' => ['billing.js'],
        ], 'portal');
    }

    public function pay(Request $request, string $key): void
    {
        $invoice = ModuleBillingService::invoiceFor($key, (string) $request->str('period'), (int) Auth::user()['id']);
        if (is_string($invoice)) {
            $this->backWithErrors('/admin/billing', ['form' => $invoice]);
        }
        $me = Auth::user();
        $url = null;
        $error = ModuleBillingService::startBillplz((int) $invoice['id'], ['name' => $me['name'], 'email' => $me['email']], $url);
        if ($error !== null) {
            $this->backWithErrors('/admin/billing', ['form' => $error]);
        }
        if (!is_string($url) || !BillplzGateway::safeUrl($url)) {
            Response::abort(502);
        }
        $this->redirect($url);
    }

    /** Pays several modules in one Billplz bill. Items are "key:period" pairs from the selected cards. */
    public function paySelected(Request $request): void
    {
        $ids = $this->selectedInvoices($request);
        $me = Auth::user();
        $url = null;
        $error = ModuleBillingService::startBillplzMany($ids, ['name' => $me['name'], 'email' => $me['email']], $url);
        if ($error !== null) {
            $this->backWithErrors('/admin/billing', ['form' => $error]);
        }
        if (!is_string($url) || !BillplzGateway::safeUrl($url)) {
            Response::abort(502);
        }
        $this->redirect($url);
    }

    /** Uploads one transfer receipt that covers several selected modules. */
    public function receiptSelected(Request $request): void
    {
        $ids = $this->selectedInvoices($request);
        $error = ModuleBillingService::attachReceiptMany($ids, $_FILES['receipt'] ?? [], (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors('/admin/billing', ['form' => $error]);
        }
        $this->success('/admin/billing', 'Receipt sent for ' . count($ids) . ' module(s). The superadmin will check it.');
    }

    /**
     * Open invoices for the selected "key:period" items, all tied to one group reference.
     * @return array<int,int> invoice ids
     */
    private function selectedInvoices(Request $request): array
    {
        $items = $request->input('items', []);
        if (!is_array($items) || $items === []) {
            $this->backWithErrors('/admin/billing', ['form' => 'Choose at least one module and plan.']);
        }
        $group = bin2hex(random_bytes(8));
        $ids = [];
        foreach (array_slice(array_unique($items), 0, 20) as $item) {
            if (!is_string($item) || !str_contains($item, ':')) {
                continue;
            }
            [$key, $period] = explode(':', $item, 2);
            $invoice = ModuleBillingService::invoiceFor($key, $period, (int) Auth::user()['id']);
            if (is_string($invoice)) {
                $this->backWithErrors('/admin/billing', ['form' => $invoice]);
            }
            \App\Core\Database::query('UPDATE module_invoices SET group_ref = ? WHERE id = ?', [$group, (int) $invoice['id']]);
            $ids[] = (int) $invoice['id'];
        }
        if ($ids === []) {
            $this->backWithErrors('/admin/billing', ['form' => 'Choose at least one module and plan.']);
        }
        return $ids;
    }

    public function receipt(Request $request, string $key): void
    {
        $invoice = ModuleBillingService::invoiceFor($key, (string) $request->str('period'), (int) Auth::user()['id']);
        if (is_string($invoice)) {
            $this->backWithErrors('/admin/billing', ['form' => $invoice]);
        }
        $error = ModuleBillingService::attachReceipt((int) $invoice['id'], $_FILES['receipt'] ?? [], (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors('/admin/billing', ['form' => $error]);
        }
        $this->success('/admin/billing', 'Receipt sent. The superadmin will check it and turn the module on.');
    }
}
