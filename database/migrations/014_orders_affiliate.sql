-- affiliate_id: who referred this order (NULL = none / self-referral blocked).
-- commission_rate: % of margin snapshotted per line at checkout so later setting changes never rewrite history.
ALTER TABLE orders ADD COLUMN affiliate_id INT UNSIGNED NULL, ADD KEY idx_orders_affiliate (affiliate_id),
    ADD CONSTRAINT fk_orders_affiliate FOREIGN KEY (affiliate_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE order_items ADD COLUMN commission_rate DECIMAL(5,2) NULL;
