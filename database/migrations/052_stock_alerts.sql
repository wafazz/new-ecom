-- "Tell me when it's back" sign-ups. One row per product (variant_key 0) or variant, per email address.
-- notified_at is set once the email has been sent, so each alert fires once per restock.
CREATE TABLE stock_alerts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id   INT UNSIGNED NOT NULL,
    variant_key  INT UNSIGNED NOT NULL DEFAULT 0,
    email        VARCHAR(190) NOT NULL,
    user_id      INT UNSIGNED NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at  DATETIME     NULL,
    UNIQUE KEY uq_stock_alert (product_id, variant_key, email),
    KEY idx_stock_alert_pending (product_id, variant_key, notified_at),
    CONSTRAINT fk_stock_alerts_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
