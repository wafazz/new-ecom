<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/**
 * Master on/off switches for whole features (flash sales, and any later module).
 * Only a superadmin can change them (Superadmin > Modules). A switched-off module is blocked everywhere:
 * its routes return 404, its menu items hide, and its storefront output and prices stop applying.
 * Stored in `settings` as module.<key> = 1 or 0. A module that was never set is on.
 */
final class Modules
{
    /** key => [label, what switching it off does]. Add a row here to make a new module controllable. */
    public const LIST = [
        'flash_sales' => ['Flash sales', 'Time-limited discounts on products. Off hides the Flash sales menus and storefront blocks, and prices go back to normal at once.'],
        'affiliates' => ['Affiliate programme', 'Referral links, commissions and withdrawals. Off stops new referral tracking and commissions and hides the affiliate pages; existing commissions and payouts stay on record.'],
        'digital_products' => ['Digital products', 'Digital downloads and licence keys. Off hides them from the shop and stops new purchases. Existing buyers keep their downloads.'],
        'stock_movements' => ['Stock movement log', 'The Stock Movement page and the Movement links on Stock Control. Off hides the page only: stock is still changed and every change is still recorded.'],
        'vouchers' => ['Discount coupons', 'Discount codes shoppers enter at checkout, and the Vouchers menu. Off stops every code at once; orders that already used a code keep their discount.'],
    ];

    public static function enabled(string $key): bool
    {
        if (!isset(self::LIST[$key])) {
            return false; // an unknown key is never switched on by accident
        }
        return Setting::get('module.' . $key, '1') === '1';
    }

    public static function set(string $key, bool $on): void
    {
        if (!isset(self::LIST[$key])) {
            throw new \InvalidArgumentException('Unknown module: ' . $key);
        }
        Setting::set('module.' . $key, $on ? '1' : '0');
    }

    /** @return array<int,array{key:string,label:string,help:string,enabled:bool}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::LIST as $key => [$label, $help]) {
            $out[] = ['key' => $key, 'label' => $label, 'help' => $help, 'enabled' => self::enabled($key)];
        }
        return $out;
    }
}
