-- A discount is either a percentage off (kind 'percent', value in percent) or a fixed price (kind 'fixed', value in RM).
-- A fixed price never raises a module above its normal price.
ALTER TABLE module_discounts
    ADD COLUMN kind ENUM('percent','fixed') NOT NULL DEFAULT 'percent' AFTER scope,
    ADD COLUMN value DECIMAL(10,2) NULL AFTER kind;
UPDATE module_discounts SET value = percent WHERE value IS NULL;
ALTER TABLE module_discounts MODIFY percent DECIMAL(5,2) NULL;
