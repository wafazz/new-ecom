<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/**
 * Master on/off switches for whole features (flash sales, and any later module).
 * Only a superadmin can change them (Superadmin > Modules). A switched-off module is blocked everywhere:
 * its routes return 404, its menu items hide, and its storefront output and prices stop applying.
 * Stored in `settings` as module.<key> = 1 or 0. A module that was never set is on, except the modules in OFF_BY_DEFAULT,
 * which stay off until a superadmin switches them on.
 */
final class Modules
{
    /** key => [label, what switching it off does]. Add a row here to make a new module controllable. */
    public const LIST = [
        'flash_sales' => ['Flash sales', 'Time-limited discounts on products. Off hides the Flash sales menus and storefront blocks, and prices go back to normal at once.'],
        'affiliates' => ['Affiliate programme', 'Referral links, commissions and withdrawals. Off stops new referral tracking and commissions and hides the affiliate pages; existing commissions and payouts stay on record.'],
        'digital_products' => ['Digital products', 'Digital downloads and licence keys. Off hides them from the shop and stops new purchases. Existing buyers keep their downloads.'],
        'newsletter' => ['Newsletter sign-up', 'Sign-up boxes in the footer and at checkout, and the Newsletter list for admins. Off hides them and stops new sign-ups. Subscribers already on the list are kept.'],
        'back_in_stock' => ['Back-in-stock alerts', 'A "tell me when it\'s back" sign-up on sold-out products, and the emails sent on restock. Off hides the sign-up and stops new alerts. Waiting alerts are kept.'],
        'sales_reports' => ['Sales reports', 'The Sales reports page with CSV download, for admins. Off hides it and its menu item. Order data is not changed.'],
        'bulk_pricing' => ['Bulk price editor', 'Change many regular prices by a percentage, with a preview and undo, for admins. Off hides the page. Prices already changed are kept.'],
        'product_csv' => ['Product import and export', 'Export all prices and stock as a spreadsheet, or upload one to change them by SKU. Off hides the page. Nothing is changed by the switch.'],
        'returns' => ['Returns and refunds', 'Return requests from customers, approval, stock return and refund records. Off hides the return form and the admin page. Open requests are kept.'],
        'support' => ['Order messages', 'A message thread on each order, between the customer and the shop, with the admin reply list. Off hides the thread on order pages and the admin list. Existing messages are kept.'],
        'loyalty' => ['Loyalty points', 'Members earn points on completed orders and can spend them at checkout. Off hides the points at checkout and stops earning and spending. Balances are kept.'],
        'gift_cards' => ['Gift cards', 'Gift cards admins issue and customers redeem at checkout. Off hides the checkout box and stops new redemptions. Existing cards and balances are kept.'],
        'bundles' => ['Bundles', 'Products made of other products, sold at one price. Off stops new bundle sales and hides the bundle list. Orders already placed are still returned to stock correctly.'],
        'tax' => ['SST on orders', 'SST worked out on tax-inclusive prices and shown at checkout, on orders and on invoices, with a monthly SST report. Off shows no SST and stores none on new orders. Existing orders keep their SST.'],
        'abandoned_cart_emails' => ['Abandoned cart emails', 'A reminder email with a restore link, for signed-in members who leave items in their cart. Off stops the reminders and the restore link. Snapshots already saved are kept.'],
        'price_alerts' => ['Price-drop alerts', 'A "tell me when it drops to RM X" sign-up on products, and the email when the price reaches the target. Off hides the sign-up and stops new alerts. Waiting alerts are kept.'],
        'accounting_export' => ['Accounting export', 'A monthly journal (sales, postage, SST, payments, refunds) as CSV for accounting software. Read only; it changes no orders. Off hides the page.'],
        'stock_counts' => ['Stock count sheets', 'Print a count sheet for a category or all products, enter the counts blind, review the differences and apply them as stock corrections. Off hides the pages.'],
        'stock_movements' => ['Stock movement log', 'The Stock Movement page and the Movement links on Stock Control. Off hides the page only: stock is still changed and every change is still recorded.'],
        'vouchers' => ['Discount coupons', 'Discount codes shoppers enter at checkout, and the Vouchers menu. Off stops every code at once; orders that already used a code keep their discount.'],
    ];

    /** Every module is off until a superadmin turns it on. */
    public const OFF_BY_DEFAULT = [
        'flash_sales', 'vouchers', 'affiliates', 'digital_products', 'stock_movements', 'price_alerts', 'accounting_export', 'stock_counts',
        'abandoned_cart_emails', 'back_in_stock', 'product_csv', 'bulk_pricing', 'returns', 'loyalty', 'gift_cards',
        'bundles', 'newsletter', 'sales_reports', 'support', 'tax',
    ];

    public static function enabled(string $key): bool
    {
        if (!isset(self::LIST[$key])) {
            return false; // an unknown key is never switched on by accident
        }
        $default = in_array($key, self::OFF_BY_DEFAULT, true) ? '0' : '1';
        if (Setting::get('module.' . $key, $default) !== '1') {
            return false;
        }
        // Switched on, but a priced module runs only while it is paid up (it switches off at lapse, on again when paid).
        return ModuleBillingService::covered($key);
    }

    /** The superadmin's switch itself (before the paid-up check). */
    public static function switchedOn(string $key): bool
    {
        return Setting::get('module.' . $key, in_array($key, self::OFF_BY_DEFAULT, true) ? '0' : '1') === '1';
    }

    /** Switched on by a superadmin but not paid up: shown on the Modules page as waiting for payment. */
    public static function waitingForPayment(string $key): bool
    {
        return Setting::get('module.' . $key, in_array($key, self::OFF_BY_DEFAULT, true) ? '0' : '1') === '1'
            && !ModuleBillingService::covered($key);
    }

    /** Whether a module is off until switched on (shown on the Modules page). */
    public static function offByDefault(string $key): bool
    {
        return in_array($key, self::OFF_BY_DEFAULT, true);
    }

    public static function set(string $key, bool $on): void
    {
        if (!isset(self::LIST[$key])) {
            throw new \InvalidArgumentException('Unknown module: ' . $key);
        }
        Setting::set('module.' . $key, $on ? '1' : '0');
        ModuleBillingService::flushCache();
    }

    /** @return array<int,array{key:string,label:string,help:string,enabled:bool}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::LIST as $key => [$label, $help]) {
            $out[] = ['key' => $key, 'label' => $label, 'help' => $help, 'enabled' => self::enabled($key), 'off_by_default' => self::offByDefault($key), 'waiting' => self::waitingForPayment($key)];
        }
        return $out;
    }
}
