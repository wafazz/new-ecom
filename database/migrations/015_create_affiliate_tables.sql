CREATE TABLE affiliate_clicks (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    ip_hash      CHAR(64) NOT NULL,
    user_agent   VARCHAR(255) NULL,
    clicked_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_clicks_dedupe (affiliate_id, ip_hash, clicked_at),
    CONSTRAINT fk_clicks_affiliate FOREIGN KEY (affiliate_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE affiliate_payouts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    amount       DECIMAL(10,2) NOT NULL,
    reference    VARCHAR(190) NOT NULL,
    note         VARCHAR(255) NULL,
    paid_by      INT UNSIGNED NULL,
    paid_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payouts_affiliate (affiliate_id),
    CONSTRAINT fk_payouts_affiliate FOREIGN KEY (affiliate_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payouts_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One commission per order (UNIQUE makes creation idempotent). base_amount = margin the rate was applied to.
CREATE TABLE affiliate_commissions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id     INT UNSIGNED NOT NULL,
    affiliate_id INT UNSIGNED NOT NULL,
    base_amount  DECIMAL(10,2) NOT NULL,
    amount       DECIMAL(10,2) NOT NULL,
    status       ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
    payout_id    INT UNSIGNED NULL,
    decided_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_commission_order (order_id),
    KEY idx_commission_affiliate (affiliate_id, status),
    CONSTRAINT fk_commission_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_commission_affiliate FOREIGN KEY (affiliate_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_commission_payout FOREIGN KEY (payout_id) REFERENCES affiliate_payouts(id) ON DELETE SET NULL,
    CONSTRAINT fk_commission_by FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
