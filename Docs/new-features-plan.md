# New features plan (items 1–6, 8, 9, 10)

As of 2026-11-14. Item 7 (e-invoice submission) is not in this plan.

Every feature gets a superadmin switch, is off by default, and follows the same pattern as the existing modules.
Migrations continue from 068. Tier T2 means the full review and test gates apply, because each one touches
money, stock or customer data.

## Overview

| # | Feature | Switch key | Size | Tier | New tables | Main risk |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Quantity price breaks | quantity_breaks | M | T2 | product_price_breaks | totals wrong when a discount also applies |
| 2 | Store credit | store_credit | M | T2 | credit_ledger | credit spent twice |
| 3 | Pre-orders | preorders | L | T2 | none (product fields and order status) | selling more than can be supplied |
| 4 | Subscriptions for shoppers | subscriptions | XL | T2 | subscriptions, subscription_runs | payments that cannot repeat automatically |
| 5 | Member-only prices | member_prices | M | T2 | member_groups, member_group_prices | a wholesale price leaking to the public |
| 6 | Price-drop alerts | price_alerts | S | T2 | price_alerts | alerts for changes that are reverted |
| 8 | Supplier purchase orders | purchase_orders | L | T2 | suppliers, purchase_orders, purchase_order_items | stock received twice |
| 9 | Accounting export | accounting_export | M | T2 | none (reads orders and ledgers) | journal entries that do not balance |
| 10 | Stock count sheets | stock_counts | M | T2 | stock_counts, stock_count_lines | a count applied to the wrong item |

## Suggested build order

Small and read-only work comes first. Features that change money or stock follow, and subscriptions come last because
they need a decision on how repeat payment works.

1. **Price-drop alerts (6).** Smallest. It reuses the back-in-stock sign-up and queue.
2. **Accounting export (9).** Read-only, so it is low risk, and it gives the numbers to check the money features against.
3. **Stock count sheets (10).** Changes stock, but only through the existing correction reason.
4. **Quantity price breaks (1).** First change to checkout pricing.
5. **Member-only prices (5).** Builds on the same pricing path.
6. **Store credit (2).** Uses the ledger pattern from loyalty points.
7. **Supplier purchase orders (8).** Adds stock in. Needs the count sheets to be trusted first.
8. **Pre-orders (3).** Changes what can be sold before stock exists.
9. **Subscriptions (4).** Last, after the open questions below are answered.

## 1. Quantity price breaks

**What it does.** A product can have lower unit prices for larger quantities, for example RM10 each, or RM8 each from 10 units.
The price applies to the total quantity of that product in the cart, across its options.

**Scope.**
- Admin sets breaks per product: minimum quantity and unit price. A break must be lower than the product's normal price.
- The product page and cart show the next break, for example "Buy 10 for RM8 each".
- Checkout charges the break price for the whole line once the quantity qualifies.

**Changes.** `product_price_breaks` (product_id, min_qty, unit_price). Pricing in `PricingService`, cart summary,
checkout. The product form gets a small table of breaks.

**Acceptance.**
- A cart with 9 units charges the normal price; 10 units charges the break price for all 10.
- Invoice totals equal checkout totals.
- Break prices do not stack with sale prices. The lower of the two applies, and the rule is the same at checkout and on the invoice.

**Open questions.** Should a break combine with a voucher, or does a voucher replace it? Default: a voucher applies on top.

## 2. Store credit

**What it does.** A customer can hold a balance of store credit. Credit is added when the admin refunds an order as
credit instead of cash. It can be spent at checkout, like a gift card.

**Scope.**
- In the returns flow (already built), the refund step asks: cash, or store credit.
- A member's credit is shown on the account page and at checkout.
- Credit is spent up to the items total, and the rest is paid another way.

**Changes.** `credit_ledger` (user_id, amount, kind earn | spend | reverse, order_id), following the loyalty ledger.
Refund step in `ReturnService`. Checkout in `CheckoutService`.

**Acceptance.**
- A credit refund adds exactly the refunded amount, once.
- Two checkouts cannot spend the same credit (row lock, as with gift cards).
- A balance never goes below zero.

**Open questions.** Does credit expire? Default: no.

## 3. Pre-orders

**What it does.** A product can be sold before stock arrives. It has an expected date. Orders are taken, payment is taken,
and fulfilment waits until stock is received.

**Scope.**
- Admin marks a product as pre-order with an expected date and a limit on how many can be sold.
- The product page shows "Pre-order, expected <date>".
- Orders stay in a "awaiting stock" state and do not reduce stock until the stock is received.
- When stock is received, pre-orders move to processing in order.
- If the expected date passes with no stock, the admin can cancel and refund in one step.

**Changes.** Product fields: preorder flag, expected date, limit. Order items record that they are pre-order lines.
`OrderService` gets an "awaiting stock" path. Stock receipt links to the purchase order work (item 8) when that exists.

**Acceptance.**
- Pre-order lines never reduce stock until received.
- The sold limit cannot be exceeded, even with two checkouts at once.
- Cancelling a pre-order restores nothing, because stock was never taken.

**Open questions.** Should payment be taken at order or at dispatch? Default: at order, through the existing methods.

## 4. Subscriptions for shoppers

**What it does.** A shopper can subscribe to a product, delivered every month or every two weeks. They can pause or cancel.

**Scope.**
- A subscription records the product, quantity, interval, next date, address and payment method.
- On each due date, the system creates a normal order and notifies the shopper to pay.
- Pause and cancel are available to the shopper and to the admin.

**Changes.** `subscriptions`, `subscription_runs`. A daily cron job creates orders for due subscriptions. A shopper page
to manage them.

**Acceptance.**
- Each due date creates exactly one order, even if the cron job runs twice.
- A paused subscription creates no orders.
- A cancelled subscription creates no orders from its cancellation date.

**Open questions (needed before this starts).**
- Billplz does not charge a saved card automatically in this system. Does each cycle need the shopper to pay by hand,
  or should the system use a recurring payment provider?
- If a cycle is not paid, is the subscription paused, cancelled, or left open?

## 5. Member-only prices

**What it does.** Members can belong to a price group, for example wholesale or VIP. A group has its own price for some
products. Everyone else sees the normal price.

**Scope.**
- Admin creates groups and assigns members to them.
- A product can have a group price per group.
- Members in a group see the group price on the product page, in the cart and at checkout. Other shoppers never see it.

**Changes.** `member_groups`, `member_group_prices`, and a group id on users. `PricingService` checks the group.
Storefront queries must not show group prices to guests.

**Acceptance.**
- A guest and a member outside the group see the normal price.
- A group price is never shown in listings, search or the API to someone outside the group.

**Open questions.** Should a group price also apply to the affiliate commission? Default: no, commission uses the normal price.

## 6. Price-drop alerts

**What it does.** A shopper asks to be told when a product falls to or below a target price. The same sign-up as
back-in-stock, but for price.

**Scope.**
- "Tell me when it drops to RM X" on the product page, with the current price shown.
- When a price changes to or below the target, one email is sent.
- Each alert fires once, and the shopper can stop alerts at any time.

**Changes.** `price_alerts` (product_id, target_price, email, user_id, notified_at), following `stock_alerts`. A queue
job runs when a price changes, from the product editor, bulk prices (item already built) and CSV import.

**Acceptance.**
- An alert fires only when the new price is at or below the target.
- A price rise and then a fall again sends one email, not two.
- Unsubscribed addresses are skipped.

## 8. Supplier purchase orders

**What it does.** The admin records suppliers and creates purchase orders for stock to buy. When the goods arrive, the
admin receives them, and stock goes up.

**Scope.**
- Suppliers: name, contact, lead time.
- Purchase order: supplier, date, items with quantity and cost, status draft, sent, part-received, received.
- Low-stock report suggests quantities to order. The admin confirms each one.
- Receiving records the quantity received per line. Stock goes up once per line received.

**Changes.** `suppliers`, `purchase_orders`, `purchase_order_items`. Receiving goes through `StockService` with reason
"restock" (already a valid reason), so it appears in the movement log.

**Acceptance.**
- Receiving a line twice does not add stock twice.
- Quantity received cannot exceed quantity ordered, unless the admin confirms an over-delivery.
- Each purchase order can be traced to its stock movements.

**Open questions.** Does the shop need purchase costs for margin reports? Default: record cost per line, and show it on the order.

## 9. Accounting export

**What it does.** A monthly journal file that imports into accounting software. It lists sales, refunds, SST, discounts
and payments by method, with a chart of accounts the admin maps once.

**Scope.**
- Export by month, as CSV in a format the admin chooses (generic, or a named format once the accountant supplies one).
- Each row is a journal line with account, debit, credit and reference.
- The file balances: total debits equal total credits, and the export refuses to run if it does not.

**Changes.** No new business tables. A chart-of-accounts setting, and a service that reads orders, refunds, SST and
ledgers.

**Acceptance.**
- Debits equal credits for every month.
- Totals match the sales report (item already built) for the same period.
- Cancelled orders are not exported.

**Open questions (needed from the accountant).** Which chart of accounts and which import format?

## 10. Stock count sheets

**What it does.** Print a count sheet for a category or shelf. The counter writes the numbers they see. The system shows
the difference, and the admin applies the counts that are right.

**Scope.**
- Create a count for a category, with a printable sheet listing SKU, name and the system quantity left blank.
- The counter enters counted quantities on a phone or on paper, then the admin types them in.
- The review page lists each difference. The admin applies all, or selected lines.

**Changes.** `stock_counts`, `stock_count_lines`. Applying uses `StockService::adjust` with reason "correction".

**Acceptance.**
- A count can be applied once. A second apply changes nothing.
- Lines not counted are left as they are.
- Each applied line appears in the stock movement log.

## Shared notes

- Every feature has a superadmin switch, off by default, and a README line.
- Every feature gets database tests where it changes money or stock, and runs them on a `_test` database before it ships.
- No feature is committed until its checks pass on the VPS, as before.
