-- Return and refund requests. Workflow: requested -> approved -> received -> refunded, or requested -> rejected.
-- Stock goes back once, when the return is received. A refund is recorded here; no payment is sent by the shop.
CREATE TABLE return_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    status          ENUM('requested','approved','rejected','received','refunded') NOT NULL DEFAULT 'requested',
    reason          VARCHAR(500) NOT NULL,
    staff_note      VARCHAR(500) NULL,
    refund_amount   DECIMAL(10,2) NULL,
    stock_restored  TINYINT(1)   NOT NULL DEFAULT 0,
    requested_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at      DATETIME     NULL,
    decided_by      INT UNSIGNED NULL,
    refunded_at     DATETIME     NULL,
    KEY idx_returns_order (order_id, status),
    CONSTRAINT fk_returns_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
