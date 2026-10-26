-- Digital products: no postage, no stock count, delivered after payment by one of three ways.
--   file  : an uploaded file, served through a private download link (kept outside the web root)
--   link  : an external link, sent through the same download link
--   keys  : license keys from a pool, shown on the order once it is paid
-- Expiry (days) and the download limit are set by HQ per product; each sale keeps the values it was sold with.

ALTER TABLE products
    ADD COLUMN product_type ENUM('physical', 'digital') NOT NULL DEFAULT 'physical' AFTER status,
    ADD COLUMN digital_delivery ENUM('file', 'link', 'keys') NULL AFTER product_type,
    ADD COLUMN digital_file_path VARCHAR(120) NULL AFTER digital_delivery,
    ADD COLUMN digital_file_name VARCHAR(190) NULL AFTER digital_file_path,
    ADD COLUMN digital_link VARCHAR(500) NULL AFTER digital_file_name,
    ADD COLUMN digital_expiry_days SMALLINT UNSIGNED NULL AFTER digital_link,
    ADD COLUMN digital_max_downloads SMALLINT UNSIGNED NULL AFTER digital_expiry_days;

-- Delivery method as it was when the item was bought (NULL = a physical item that ships).
ALTER TABLE order_items
    ADD COLUMN digital_delivery VARCHAR(10) NULL AFTER commission_rate;

-- License keys are encrypted (SecretBox). A key belongs to one order item once it has been sold.
CREATE TABLE product_license_keys (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id    INT UNSIGNED NOT NULL,
    key_enc       TEXT         NOT NULL,
    order_item_id INT UNSIGNED NULL,
    assigned_at   DATETIME     NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_keys_product_free (product_id, order_item_id),
    CONSTRAINT fk_keys_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_keys_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One download grant per paid file/link item. token_hash is what the download link is checked against;
-- token_enc lets the order page show the link again to the person who owns the order.
-- The file and link are copied here, so later edits to the product do not change what was sold.
CREATE TABLE digital_grants (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id      INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    token_enc     TEXT         NOT NULL,
    delivery      ENUM('file', 'link') NOT NULL,
    file_path     VARCHAR(120) NULL,
    file_name     VARCHAR(190) NULL,
    link          VARCHAR(500) NULL,
    expires_at    DATETIME     NOT NULL,
    max_downloads SMALLINT UNSIGNED NOT NULL,
    downloads     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_grant_item (order_item_id),
    UNIQUE KEY uq_grant_token (token_hash),
    KEY idx_grant_order (order_id),
    CONSTRAINT fk_grant_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_grant_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
