-- Flash sales: a named timeframe (starts_at to ends_at) with products that take a percentage off while it runs.
-- Several timeframes can exist; a product can sit in more than one, and where they overlap the bigger percentage wins.
CREATE TABLE flash_sales (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    starts_at   DATETIME     NOT NULL,
    ends_at     DATETIME     NOT NULL,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_flash_sales_window (active, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE flash_sale_products (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flash_sale_id  INT UNSIGNED NOT NULL,
    product_id     INT UNSIGNED NOT NULL,
    percent_off    DECIMAL(5,2) NOT NULL,
    UNIQUE KEY uq_flash_sale_product (flash_sale_id, product_id),
    KEY idx_flash_sale_products_product (product_id),
    CONSTRAINT fk_flash_sale_products_sale FOREIGN KEY (flash_sale_id) REFERENCES flash_sales (id) ON DELETE CASCADE,
    CONSTRAINT fk_flash_sale_products_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
