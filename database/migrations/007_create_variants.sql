CREATE TABLE product_options (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    name       VARCHAR(50) NOT NULL,
    position   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_option_name (product_id, name),
    CONSTRAINT fk_options_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_option_values (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    option_id INT UNSIGNED NOT NULL,
    value     VARCHAR(50) NOT NULL,
    position  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_option_value (option_id, value),
    CONSTRAINT fk_values_option FOREIGN KEY (option_id) REFERENCES product_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Price columns are NULL = inherit from the product.
CREATE TABLE product_variants (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id         INT UNSIGNED NOT NULL,
    sku                VARCHAR(60) NULL,
    base_price         DECIMAL(10,2) NULL,
    company_base_price DECIMAL(10,2) NULL,
    retail_price       DECIMAL(10,2) NULL,
    discount_price     DECIMAL(10,2) NULL,
    stock              INT NOT NULL DEFAULT 0,
    deleted_at         DATETIME NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_variants_sku (sku),
    KEY idx_variants_product (product_id, deleted_at),
    CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE variant_option_values (
    variant_id      INT UNSIGNED NOT NULL,
    option_value_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (variant_id, option_value_id),
    CONSTRAINT fk_vov_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
    CONSTRAINT fk_vov_value FOREIGN KEY (option_value_id) REFERENCES product_option_values(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
