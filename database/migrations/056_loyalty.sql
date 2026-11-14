-- Loyalty points ledger. 1 point is worth RM0.01 (100 points = RM1). Points are earned when an order completes,
-- spent at checkout, and reversed when a refund is recorded. Each order can have one earn, one spend and one reverse.
CREATE TABLE loyalty_ledger (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    points      INT NOT NULL,
    kind        ENUM('earn','spend','reverse','adjust') NOT NULL,
    order_id    INT UNSIGNED NULL,
    note        VARCHAR(190) NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loyalty_order_kind (order_id, kind),
    KEY idx_loyalty_user (user_id),
    CONSTRAINT fk_loyalty_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_loyalty_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
