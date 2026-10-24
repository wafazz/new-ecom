CREATE TABLE brands (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    slug       VARCHAR(140) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_brands_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE products
    ADD COLUMN brand_id INT UNSIGNED NULL AFTER category_id,
    ADD KEY idx_products_brand (brand_id),
    ADD CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE SET NULL;
