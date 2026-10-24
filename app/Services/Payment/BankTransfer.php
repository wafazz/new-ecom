<?php
declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\PaymentSettings;

final class BankTransfer implements PaymentMethod
{
    public function code(): string { return 'bank_transfer'; }

    public function label(): string { return 'Bank transfer'; }

    public function instructions(): string
    {
        $b = PaymentSettings::bank();
        $text = PaymentSettings::bankComplete($b)
            ? sprintf('Transfer the total to %s, account %s (%s). Use your order number as the reference. We ship after payment is confirmed.', $b['name'], $b['number'], $b['holder'])
            : 'Transfer the total to the shop\'s bank account and use your order number as the reference. We ship after payment is confirmed.';
        return $b['note'] !== '' ? $text . ' ' . $b['note'] : $text;
    }

    /** Offered only when it is switched on AND the account details are filled in (customers must never see blanks or placeholders). */
    public function available(): bool { return PaymentSettings::enabled('bank_transfer') && PaymentSettings::bankComplete(); }
}
