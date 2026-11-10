-- A cart line holds its stock until reserved_until. Adding or changing the line sets it to now + the
-- admin-set cart_hold_minutes. NULL or a past time means the hold has lapsed and the line is dropped.
ALTER TABLE cart_items
    ADD COLUMN reserved_until DATETIME NULL AFTER qty,
    ADD KEY idx_items_hold (product_id, variant_key, reserved_until);

-- Lines that already exist get a fresh default hold so nothing disappears on deploy.
UPDATE cart_items SET reserved_until = NOW() + INTERVAL 15 MINUTE;
