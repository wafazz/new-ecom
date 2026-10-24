-- Optional per-product commission override, % of margin. NULL = use the global `commission_rate` setting.
ALTER TABLE products ADD COLUMN commission_rate DECIMAL(5,2) NULL;
