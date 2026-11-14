<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Full details for each module, shown in the info popup on the billing page so an admin can understand it before buying:
 * what it does, where to find it, and what happens when it is switched on or off.
 */
final class ModuleInfo
{
    public const INFO = [
        'flash_sales' => [
            'what' => 'Time-limited sale prices on products, with a start and an end time. Sales appear as a block on the shop front.',
            'where' => 'Catalog > Flash sales (admin and staff).',
            'on' => 'Sales show on the shop and their prices apply at checkout.',
            'off' => 'Sales are hidden and prices go back to normal straight away. Sale records are kept.',
        ],
        'vouchers' => [
            'what' => 'Discount codes shoppers enter at checkout. A code can be a percentage or a fixed amount, with a minimum spend, a use limit and an expiry date.',
            'where' => 'Sales > Vouchers (admin).',
            'on' => 'Codes work at checkout.',
            'off' => 'Every code is refused at checkout. Orders that already used a code keep their discount.',
        ],
        'affiliates' => [
            'what' => 'Referral links for members. A member earns commission on sales made through their link, approved by the admin and paid out on request.',
            'where' => 'Sales > Affiliates, Commissions and Withdrawals (admin). Affiliate page for members.',
            'on' => 'Referral links are tracked and commissions are created.',
            'off' => 'Referral links are ignored and no new commission is made. Existing commissions and payouts are kept.',
        ],
        'digital_products' => [
            'what' => 'Downloadable files and licence keys sold in the shop. Nothing is posted, so there is no shipping.',
            'where' => 'Product form, choose the digital type (admin).',
            'on' => 'Digital products are shown and can be bought.',
            'off' => 'Digital products are hidden from the shop and cannot be added to a cart. Existing buyers keep their downloads.',
        ],
        'stock_movements' => [
            'what' => 'A log of every stock change, with who made it, why, and the count before and after.',
            'where' => 'Inventory > Stock Movement (admin and staff).',
            'on' => 'The log can be viewed.',
            'off' => 'The page is hidden. Stock changes are still recorded.',
        ],
        'abandoned_cart_emails' => [
            'what' => 'Emails a signed-in shopper who left items in their cart for two hours, with a link that puts the items back. Prices and stock are checked again when they are restored.',
            'where' => 'Runs by cron every 10 minutes (bin/abandoned-carts.php). Restore link in the email.',
            'on' => 'Reminders are sent to shoppers who left items behind.',
            'off' => 'No reminders are sent. Saved snapshots are kept.',
        ],
        'back_in_stock' => [
            'what' => 'A "tell me when it is back" sign-up on sold-out products. The shopper is emailed once, when stock returns.',
            'where' => 'Product page, on sold-out items.',
            'on' => 'Shoppers can sign up for restock emails.',
            'off' => 'The sign-up is hidden and no new alerts are taken. Waiting alerts are kept.',
        ],
        'product_csv' => [
            'what' => 'Export every product price and stock count to a spreadsheet, or upload a file to change them by SKU. The file is checked row by row before anything changes.',
            'where' => 'Catalog > Import / export (admin).',
            'on' => 'The export and upload page can be used.',
            'off' => 'The page is hidden. Nothing changes when the switch is turned off.',
        ],
        'bulk_pricing' => [
            'what' => 'Change many regular prices at once by a percentage, for all products, one category or one brand. A preview shows every change first, and any batch can be undone.',
            'where' => 'Catalog > Bulk prices (admin).',
            'on' => 'The bulk price page can be used.',
            'off' => 'The page is hidden. Prices that were already changed stay as they are.',
        ],
        'returns' => [
            'what' => 'Customers ask for a return on a completed order. Staff approve or reject it, mark the goods received (stock goes back), and record the refund in cash or as store credit.',
            'where' => 'Sales > Returns (admin). Return request on the customer order page.',
            'on' => 'Customers can ask for returns and staff can process them.',
            'off' => 'The return form and the admin page are hidden. Open requests are kept.',
        ],
        'loyalty' => [
            'what' => 'Members earn 1 point for each RM of a completed order and spend points at checkout, where 100 points = RM1.',
            'where' => 'Points box at checkout (members).',
            'on' => 'Points are earned on completed orders and can be spent.',
            'off' => 'No points are earned or spent. Balances are kept.',
        ],
        'gift_cards' => [
            'what' => 'Gift cards the admin issues for an amount. Customers enter the code at checkout, and the card covers up to its balance.',
            'where' => 'Sales > Gift cards (admin). Gift card box at checkout.',
            'on' => 'Gift card codes can be used at checkout.',
            'off' => 'Codes are refused at checkout. Balances are kept.',
        ],
        'bundles' => [
            'what' => 'A product made of other products, sold at one price. Its stock is worked out from the parts.',
            'where' => 'Catalog > Bundles (admin).',
            'on' => 'Bundles can be bought.',
            'off' => 'Bundles cannot be added to a cart. Orders already placed are still returned to stock correctly.',
        ],
        'newsletter' => [
            'what' => 'A newsletter sign-up in the footer and at checkout. A sign-up is only added after the person confirms by email. The admin can see and download the list.',
            'where' => 'Footer and checkout (shoppers). Newsletter page (admin).',
            'on' => 'Shoppers can sign up.',
            'off' => 'The sign-up boxes are hidden. Subscribers are kept.',
        ],
        'sales_reports' => [
            'what' => 'Sales by day or month, the top products, the split by payment method, and a CSV download. Cancelled orders are left out.',
            'where' => 'Sales > Sales reports (admin).',
            'on' => 'The reports can be viewed.',
            'off' => 'The page is hidden. No data is changed.',
        ],
        'support' => [
            'what' => 'A message thread on each order between the customer and the shop, with a list of orders waiting for a reply.',
            'where' => 'Order pages (customers). Sales > Order messages (admin).',
            'on' => 'Customers and the shop can message about an order.',
            'off' => 'Threads are hidden. Messages are kept.',
        ],
        'tax' => [
            'what' => 'SST is worked out from prices that already include it, shown at checkout and on invoices, with a monthly SST report.',
            'where' => 'Sales > SST (admin). Shown at checkout and on invoices.',
            'on' => 'SST is shown and stored on new orders.',
            'off' => 'No SST is shown or stored on new orders. Existing orders keep theirs.',
        ],
        'price_alerts' => [
            'what' => 'A "tell me when it drops to RM X" sign-up on products. The shopper is emailed once the price reaches their target.',
            'where' => 'Product page.',
            'on' => 'Shoppers can set a price alert.',
            'off' => 'The sign-up is hidden. Waiting alerts are kept.',
        ],
        'accounting_export' => [
            'what' => 'A monthly journal of sales, postage, SST, payments and refunds as a CSV for accounting software. The file is only made when debits equal credits.',
            'where' => 'Sales > Accounting export (admin).',
            'on' => 'The export can be downloaded.',
            'off' => 'The page is hidden. Nothing is changed.',
        ],
        'stock_counts' => [
            'what' => 'Print a blind count sheet for a category or all products. Counters write what they see, then the admin reviews the differences and applies them as stock corrections.',
            'where' => 'Inventory > Stock counts (admin).',
            'on' => 'Count sheets can be made and applied.',
            'off' => 'The pages are hidden. Counts already applied stay.',
        ],
        'quantity_breaks' => [
            'what' => 'A lower price per item when a shopper buys enough of one product, for example RM8 each from 10.',
            'where' => 'Catalog > Quantity prices (admin). Shown on the product page.',
            'on' => 'The lower price applies in the cart and at checkout.',
            'off' => 'Normal prices apply. Orders already placed keep the price they were charged.',
        ],
        'member_prices' => [
            'what' => 'Member groups, such as Wholesale, with lower prices on chosen products. Only members in a group see those prices.',
            'where' => 'Admin > Member prices. Shown to group members on the product page.',
            'on' => 'Group members pay their group price.',
            'off' => 'Everyone pays the public price.',
        ],
        'store_credit' => [
            'what' => 'A refund can be given as store credit to a member, who can then spend it at checkout.',
            'where' => 'Refund step on Returns (admin). Store credit at checkout (members).',
            'on' => 'Credit can be given and spent.',
            'off' => 'No new credit refunds are given and credit cannot be spent. Balances are kept.',
        ],
        'preorders' => [
            'what' => 'Sell a product before the stock arrives, up to a set limit, with an expected date. Stock is set when the goods arrive.',
            'where' => 'Catalog > Pre-orders (admin). Shown on the product page.',
            'on' => 'Shoppers can pre-order up to the limit.',
            'off' => 'No new pre-orders are taken. Existing pre-orders are kept and can still be cancelled or received.',
        ],
        'purchase_orders' => [
            'what' => 'Suppliers and purchase orders to them. Stock goes up only when a delivery is recorded, and never past the quantity ordered.',
            'where' => 'Inventory > Purchase orders (admin).',
            'on' => 'Purchase orders can be made and deliveries recorded.',
            'off' => 'The pages are hidden. Records are kept.',
        ],
        'subscriptions' => [
            'what' => 'A member subscribes to a product for regular deliveries, monthly or every two weeks. Each delivery is an order the member pays; an unpaid one pauses the subscription.',
            'where' => 'Product page (members). Member > Subscriptions. Runs hourly (bin/subscriptions.php).',
            'on' => 'Members can subscribe and deliveries are ordered when due.',
            'off' => 'No new subscriptions or deliveries. Existing subscriptions are kept.',
        ],
    ];

    /** @return array{what:string, where:string, on:string, off:string} */
    public static function for(string $key): array
    {
        return self::INFO[$key] ?? [
            'what' => Modules::LIST[$key][1] ?? '',
            'where' => 'Ask the superadmin where this module appears.',
            'on' => 'The module is switched on.',
            'off' => 'The module is switched off.',
        ];
    }
}
