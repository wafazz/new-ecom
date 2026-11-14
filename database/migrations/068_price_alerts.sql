-- "Tell me when it drops to RM X". One row per product and address. notified_at is set once the email is accepted.
CREATE TABLE price_alerts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id    INT UNSIGNED NOT NULL,
    target_price  DECIMAL(10,2) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    user_id       INT UNSIGNED NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at   DATETIME     NULL,
    UNIQUE KEY uq_price_alert (product_id, email),
    KEY idx_price_alert_pending (product_id, notified_at),
    CONSTRAINT fk_price_alerts_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
