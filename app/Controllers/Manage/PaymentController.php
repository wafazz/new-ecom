<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\Payment\Billplz;
use App\Services\Payment\Payments;
use App\Services\PaymentSettings;

/** Payment methods: switch each on or off and set the bank account for Bank transfer. Admin, or staff with payments.manage. */
final class PaymentController extends ManageController
{
    public function show(Request $request): void
    {
        $this->view('manage/payments', [
            'title' => 'Payments',
            'base' => $this->base(),
            'cod' => ['on' => PaymentSettings::enabled('cod'), 'note' => trim(\App\Models\Setting::get('pay_cod_note', ''))],
            'bank' => PaymentSettings::bank(),
            'bankOn' => PaymentSettings::enabled('bank_transfer'),
            'billplzOn' => PaymentSettings::enabled('billplz'),
            'billplzReady' => Billplz::ready(),
            'offered' => array_values(array_map(fn($m) => $m->label(), Payments::checkoutMethods())),
            'scripts' => ['payments.js'],
            'vendor' => ['select2'],
        ], 'portal');
    }

    public function update(Request $request): void
    {
        $before = array_keys(Payments::checkoutMethods());
        $errors = PaymentSettings::save($request->body);
        if ($errors !== []) {
            $this->backWithErrors($this->base() . '/payments', $errors, array_filter($request->body, 'is_scalar'));
        }
        $after = array_keys(Payments::checkoutMethods());
        AuditService::log((int) Auth::user()['id'], 'payments.update', 'offered: ' . (implode(', ', $after) ?: 'none') . ($before === $after ? '' : ' (was ' . (implode(', ', $before) ?: 'none') . ')'));
        $this->success($this->base() . '/payments', 'Payment settings saved.');
    }
}
