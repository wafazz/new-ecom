-- Discount codes. A percent code takes that share of the items (not shipping); a fixed code takes an amount off the items.
-- used_count counts orders placed with the code and goes down again when such an order is cancelled.
CREATE TABLE vouchers (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(40)   NOT NULL,
    type         ENUM('percent','fixed') NOT NULL,
    value        DECIMAL(10,2) NOT NULL,
    min_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_uses     INT UNSIGNED  NULL,
    used_count   INT UNSIGNED  NOT NULL DEFAULT 0,
    starts_at    DATETIME      NULL,
    expires_at   DATETIME      NULL,
    active       TINYINT(1)    NOT NULL DEFAULT 1,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_voucher_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN voucher_id INT UNSIGNED NULL,
    ADD COLUMN voucher_code VARCHAR(40) NULL,
    ADD COLUMN discount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD CONSTRAINT fk_orders_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE SET NULL;
