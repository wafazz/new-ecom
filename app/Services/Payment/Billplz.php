<?php
declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\BillplzSettings;

/** Customer-facing payment option. Listed at checkout only once the credentials have passed "Test connection". */
final class Billplz implements PaymentMethod
{
    public function code(): string { return 'billplz'; }

    public function label(): string { return 'Online banking / card (Billplz)'; }

    public function instructions(): string { return 'You will be taken to Billplz to pay securely (FPX online banking or card), then brought back here.'; }

    public function available(): bool
    {
        return \App\Services\PaymentSettings::enabled('billplz') && self::ready();
    }

    /** Credentials saved and the last "Test connection" passed (independent of the on/off switch). */
    public static function ready(): bool
    {
        return BillplzSettings::credentials() !== null && BillplzSettings::overview()['verified'] === 'ok';
    }
}
