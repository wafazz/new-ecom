<?php
declare(strict_types=1);

namespace App\Services\Payment;

final class Payments
{
    /** @return array<string,PaymentMethod> keyed by code */
    public static function all(): array
    {
        $out = [];
        foreach ([new CashOnDelivery(), new BankTransfer(), new Billplz()] as $m) {
            $out[$m->code()] = $m;
        }
        return $out;
    }

    /** What a customer may pick for a NEW order (all() also covers methods only needed to show old orders). */
    public static function checkoutMethods(): array
    {
        return array_filter(self::all(), fn(PaymentMethod $m) => $m->available());
    }

    public static function find(string $code): ?PaymentMethod
    {
        return self::all()[$code] ?? null;
    }

    public static function label(string $code): string
    {
        return self::find($code)?->label() ?? $code;
    }
}
