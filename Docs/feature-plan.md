# Shop feature plan: 12 new features

As of 2026-11-14 · Requested by: Fakrul

## Summary

This plan covers the 12 features from the earlier list. Each one gets a superadmin switch, so it can be turned off without a code change. We build them one at a time, in the order in section 3, and each one ships only after its tests pass.

- Approval needed: the build order, and the answers to the open questions in section 5.
- Nothing starts until you approve. After approval, each feature is built, tested, committed and pushed in turn.
- Sizes are relative: S is a few files, M is a new table and a few screens, L is several tables with money or stock logic.

## Feature overview

All 12 features get a superadmin switch. Tier T2 means the full review and test gates apply, since each one touches customer data, money or stock.

| # | Feature | Switch key | Size | Tier | New tables | Main risk |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Abandoned cart emails | abandoned_cart_emails | M | T2 | none (uses cart timestamps) | emailing shoppers who already bought |
| 2 | Back-in-stock alerts | back_in_stock | M | T2 | stock_alerts | alerts sent for stock that sells out again at once |
| 3 | CSV import and export of products | product_csv | L | T2 | import_jobs | bad rows changing prices or stock |
| 4 | Bulk price editor | bulk_pricing | M | T2 | price_changes | wrong percentage applied to the whole catalogue |
| 5 | Refund and return requests | returns | L | T2 | return_requests | refund paid out twice or stock restored wrongly |
| 6 | Loyalty points | loyalty | L | T2 | point_ledger | points that stay after a refund |
| 7 | Gift cards | gift_cards | L | T2 | gift_cards, gift_card_ledger | guessed card codes |
| 8 | Bundles | bundles | L | T2 | bundle_items | stock sold for a part that is out |
| 9 | Newsletter sign-up | newsletter | S | T2 | newsletter_subscribers | signing up people without consent |
| 10 | Sales reports | sales_reports | M | T2 | none (reads orders) | personal data in exports |
| 11 | Customer support tickets | support | L | T2 | support_tickets, support_messages | customer messages seen by the wrong person |
| 12 | SST tax on orders | tax | L | T2 | tax_rates | wrong tax amounts on invoices |

New tables are listed, and every migration gets the next number after 050.

## Build order

Small features come first, so the team learns the patterns on low-risk work. Features that change money or stock come after the simpler ones, and tax comes last because it needs an accounting decision.

1. **Newsletter sign-up (S).** Smallest change. It sets up the consent and unsubscribe pattern the email features reuse.
2. **Abandoned cart emails (M).** Reuses the email queue and unsubscribe from step 1. Can offer a voucher, using the coupons switch that already exists.
3. **Back-in-stock alerts (M).** Reuses the same email queue. Adds a hook in stock changes.
4. **Sales reports (M).** Read-only, so low risk. Gives you the numbers to check the money features against.
5. **Bulk price editor (M).** First feature that changes prices. Needs a preview and an audit trail.
6. **CSV import and export (L).** Builds on the bulk price editor's rules for changing prices and stock in bulk.
7. **Refund and return requests (L).** Adds the order-state workflow that loyalty points and gift cards must reverse on.
8. **Customer support tickets (L).** Independent of money, so it can follow the returns work.
9. **Loyalty points (L).** Needs the refund reversal from step 7.
10. **Gift cards (L).** Reuses the ledger design from loyalty points.
11. **Bundles (L).** Changes stock rules, so it comes after the stock-sensitive work above.
12. **SST tax on orders (L).** Last, because the rates and whether prices include tax need your decision (section 5).

## Scope and acceptance

Each feature is done when its acceptance points pass and its switch turns it off cleanly.

1. **Newsletter sign-up.** Footer and checkout sign-up form. Double opt-in: a confirmation email before the address is added. Uses the existing unsubscribe link. Accepted when: no address is added before confirmation, and unsubscribing works.
2. **Abandoned cart emails.** Sends one reminder to a signed-in shopper whose cart is idle for a set time. Setting for the delay and an optional voucher. Accepted when: no email goes to someone who has since ordered or unsubscribed, and each cart gets one reminder.
3. **Back-in-stock alerts.** A notify button on sold-out products. Email when stock goes from zero to above zero. Accepted when: one email per alert, and a product that sells out again does not resend.
4. **Sales reports.** Daily and monthly sales, top products, and payment method split, with CSV export. Admin and superadmin only. Accepted when: totals match the orders list for the same dates.
5. **Bulk price editor.** Change prices for a category or brand by a percentage, with a preview of every affected product before saving. Recorded in the audit log. Accepted when: the preview matches the saved result, and undo restores the old prices.
6. **CSV import and export.** Upload a spreadsheet of products or prices, check it row by row, then confirm. Export the catalogue in the same format. Accepted when: a bad row blocks only itself, and export then import changes nothing.
7. **Refund and return requests.** Customer asks from the order page; staff approve, reject or mark received. A refund is recorded, not sent through the gateway (see section 5). Accepted when: stock returns once, and a refunded order cannot be refunded again.
8. **Customer support tickets.** A message thread on each order. Staff reply from the portal, and the customer gets an email. Accepted when: a customer sees only their own tickets.
9. **Loyalty points.** Points earned on paid orders, spent as a discount at checkout. Points are reversed when an order is refunded. Accepted when: the balance equals the ledger total, and a refund removes the points it earned.
10. **Gift cards.** Sold as a product, redeemed at checkout, balance kept in a ledger. Codes rate-limited against guessing. Accepted when: the balance never goes below zero, and two checkouts cannot spend the same balance.
11. **Bundles.** A product made of other products, sold at one price. Stock is the lowest stock of its parts, and an order takes stock from each part. Accepted when: a bundle with one part out cannot be bought.
12. **SST tax on orders.** Tax rate per product or category, shown at checkout, on the order and on the invoice. Accepted when: totals on the invoice match the checkout, and the tax report adds up.

## Open questions

These need an answer before the matching feature starts. Each one has a default, which I will use if you say nothing.

- [ ] **Abandoned cart (step 2):** how long after the last change should the reminder go? Default: 2 hours. Include a voucher? Default: no.
- [ ] **Newsletter (step 1):** is double opt-in (confirm by email) acceptable? Default: yes.
- [ ] **Refunds (step 7):** record refunds only, or send them through Billplz automatically? Default: record only, since bank transfer refunds are manual.
- [ ] **Loyalty (step 9):** points per RM spent, and do points expire? Default: 1 point per RM, expire after 12 months.
- [ ] **Gift cards (step 10):** do cards expire, and can a card buy another card? Default: no expiry, and no.
- [ ] **SST tax (step 12):** are shop prices tax-inclusive, and which products are taxable? Default: prices are tax-inclusive, and no product is taxable until you set a rate. An accountant should confirm the rules before this goes live.

To approve the plan as written, reply with the word approve.
