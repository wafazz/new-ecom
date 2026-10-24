-- A customer's proof of a bank transfer: which bank they paid from, when, and the receipt file (kept in storage/receipts, never public).
CREATE TABLE payment_proofs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id      INT UNSIGNED NOT NULL,
    bank_name     VARCHAR(80)  NOT NULL,
    paid_at       DATETIME     NOT NULL,
    file_path     VARCHAR(120) NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    mime          VARCHAR(50)  NOT NULL,
    size          INT UNSIGNED NOT NULL,
    status        ENUM('submitted','approved','rejected','superseded') NOT NULL DEFAULT 'submitted',
    note          VARCHAR(255) NULL,
    reviewed_by   INT UNSIGNED NULL,
    reviewed_at   DATETIME NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_proofs_order (order_id, id),
    KEY idx_proofs_status (status),
    CONSTRAINT fk_proofs_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_proofs_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
