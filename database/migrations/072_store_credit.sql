-- Store credit: a member's balance from refunds given as credit, spent at checkout. Each order has at most one
-- spend and one restore (a cancelled order gives its credit back once), so a movement is never recorded twice.
CREATE TABLE credit_ledger (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    kind        ENUM('refund','spend','restore') NOT NULL,
    order_id    INT UNSIGNED NULL,
    note        VARCHAR(190) NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_credit_order_kind (order_id, kind),
    KEY idx_credit_user (user_id),
    CONSTRAINT fk_credit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_credit_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE return_requests ADD COLUMN refund_method ENUM('cash','credit') NULL AFTER refund_amount;
