<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\BillplzSettings;

/** Billplz credentials. Mounted in /admin and /staff behind permission:payments.manage. */
final class BillplzController extends ManageController
{
    public function show(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('manage/billplz', [
            'title' => 'Billplz',
            'base' => $this->base(),
            'cfg' => BillplzSettings::overview(),
            'modes' => BillplzSettings::MODES,
            'history' => AuditService::recent('billplz.'),
        ], 'portal');
    }

    public function update(Request $request): void
    {
        $in = [
            'mode' => $request->str('mode'),
            'collection_id' => trim($request->str('collection_id')),
            'payment_order_collection_id' => trim($request->str('payment_order_collection_id')),
            'api_key' => trim($request->str('api_key')),
            'x_signature_key' => trim($request->str('x_signature_key')),
        ];
        $back = $this->base() . '/payments/billplz';
        $errors = BillplzSettings::validate($in);
        if ($errors !== []) {
            // Secrets are deliberately NOT echoed back into the session/old input.
            $this->backWithErrors($back, $errors, ['mode' => $in['mode'], 'collection_id' => $in['collection_id'], 'payment_order_collection_id' => $in['payment_order_collection_id']]);
        }
        $changed = BillplzSettings::save($in, (int) Auth::user()['id']);
        $this->success($back, $changed === [] ? 'Nothing changed.' : 'Billplz details saved. Use "Test connection" to confirm them.');
    }

    public function test(Request $request): void
    {
        $result = BillplzSettings::testConnection((int) Auth::user()['id']);
        $back = $this->base() . '/payments/billplz';
        $result['ok'] ? $this->success($back, $result['message']) : $this->backWithErrors($back, ['form' => $result['message']]);
    }
}
