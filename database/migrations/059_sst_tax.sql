-- SST on orders. Prices stay tax-inclusive, so the amount customers pay does not change: the SST part is worked out
-- from the paid amount (rate / (100 + rate)) and stored for the order, each item and the invoice.
-- A rate is set on a category, or on a product to override its category. A product rate of 0 means zero-rated.
ALTER TABLE categories ADD COLUMN tax_rate DECIMAL(5,2) NULL;
ALTER TABLE products   ADD COLUMN tax_rate DECIMAL(5,2) NULL;
ALTER TABLE orders     ADD COLUMN tax_total DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE order_items
    ADD COLUMN tax_rate   DECIMAL(5,2)  NULL,
    ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00;
