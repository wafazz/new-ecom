<?php
declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\PaymentSettings;

final class CashOnDelivery implements PaymentMethod
{
    public function code(): string { return 'cod'; }

    public function label(): string { return 'Cash on delivery'; }

    public function instructions(): string { return PaymentSettings::codNote(); }

    public function available(): bool { return PaymentSettings::enabled('cod'); }
}
