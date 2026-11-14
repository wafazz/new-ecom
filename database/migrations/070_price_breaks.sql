-- Quantity price breaks: a lower unit price once a shopper buys min_qty or more of a product (across its options).
CREATE TABLE product_price_breaks (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id  INT UNSIGNED NOT NULL,
    min_qty     SMALLINT UNSIGNED NOT NULL,
    unit_price  DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uq_price_break (product_id, min_qty),
    CONSTRAINT fk_price_breaks_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
