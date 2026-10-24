<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\Invoice;
use App\Services\SiteLogo;

/** The shop details printed on invoices. HQ (admin) only. */
final class InvoiceSettingsController extends Controller
{
    public function edit(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('admin/invoice', [
            'title' => 'Invoice details',
            'details' => Invoice::details(),
            'fields' => Invoice::FIELDS,
            'logoSet' => SiteLogo::file() !== null,
        ], 'portal');
    }

    public function update(Request $request): void
    {
        Invoice::saveDetails($request->body, (int) Auth::user()['id']);
        $this->success('/admin/invoice', 'Invoice details saved. New invoices use them straight away.');
    }
}
