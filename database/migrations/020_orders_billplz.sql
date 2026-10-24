-- One Billplz bill per order (UNIQUE also lets the callback find the order by bill id).
ALTER TABLE orders ADD COLUMN billplz_bill_id VARCHAR(40) NULL, ADD COLUMN billplz_url VARCHAR(255) NULL, ADD UNIQUE KEY uq_orders_billplz_bill (billplz_bill_id);
