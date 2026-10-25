-- Weight and size per product (required in the product form; existing products are filled in on their next save).
-- Shipping per order: the zone it was charged in, the billable kilos (weight rounded up) and the COD surcharge included in it.
ALTER TABLE products
    ADD COLUMN weight_kg DECIMAL(8,3) NULL AFTER retail_price,
    ADD COLUMN width_cm DECIMAL(8,2) NULL AFTER weight_kg,
    ADD COLUMN length_cm DECIMAL(8,2) NULL AFTER width_cm,
    ADD COLUMN height_cm DECIMAL(8,2) NULL AFTER length_cm;

ALTER TABLE orders
    ADD COLUMN shipping_zone VARCHAR(20) NULL,
    ADD COLUMN billable_kg INT UNSIGNED NULL,
    ADD COLUMN cod_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00;
