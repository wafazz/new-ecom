-- Pre-orders: a product can be sold before its stock arrives, up to preorder_limit units. Pre-order units never touch
-- physical stock until the goods arrive. preorder_sold counts the units sold. order_items.preorder marks those lines.
ALTER TABLE products
    ADD COLUMN preorder_expected DATE NULL,
    ADD COLUMN preorder_limit INT UNSIGNED NULL,
    ADD COLUMN preorder_sold INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE order_items ADD COLUMN preorder TINYINT(1) NOT NULL DEFAULT 0;
