<?php
declare(strict_types=1);

namespace App\Services\Payment;

interface PaymentMethod
{
    public function code(): string;

    public function label(): string;

    /** Shown to the customer on checkout and on the order page. */
    public function instructions(): string;

    /** Whether it may be offered for NEW orders right now. Existing orders always keep their method. */
    public function available(): bool;
}
