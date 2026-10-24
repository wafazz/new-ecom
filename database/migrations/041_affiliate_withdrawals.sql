-- Affiliate withdrawals: a member asks HQ to pay out any part of their approved commission.
-- A commission is one row per order, so it can be paid in parts: paid_amount is how much of it has gone out.
ALTER TABLE affiliate_commissions ADD COLUMN paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00;

CREATE TABLE affiliate_withdrawals (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id    INT UNSIGNED NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,        -- requested, before the fee
    fee             DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    net             DECIMAL(10,2) NOT NULL,        -- what the member receives
    status          ENUM('pending','paid','rejected') NOT NULL DEFAULT 'pending',
    bank_name       VARCHAR(120)  NOT NULL,
    account_holder  VARCHAR(190)  NOT NULL,
    account_no_enc  VARCHAR(255)  NOT NULL,        -- encrypted with APP_KEY (SecretBox)
    reference       VARCHAR(190)  NULL,            -- the bank transfer reference, once paid
    note            VARCHAR(255)  NULL,
    requested_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_by    INT UNSIGNED  NULL,
    processed_at    DATETIME      NULL,
    KEY idx_withdrawals_status (status, id),
    KEY idx_withdrawals_affiliate (affiliate_id),
    CONSTRAINT fk_withdrawals_affiliate FOREIGN KEY (affiliate_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawals_by FOREIGN KEY (processed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which part of which commissions a paid withdrawal came from.
CREATE TABLE affiliate_withdrawal_items (
    withdrawal_id INT UNSIGNED NOT NULL,
    commission_id INT UNSIGNED NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (withdrawal_id, commission_id),
    CONSTRAINT fk_withdrawal_items_withdrawal FOREIGN KEY (withdrawal_id) REFERENCES affiliate_withdrawals (id) ON DELETE CASCADE,
    CONSTRAINT fk_withdrawal_items_commission FOREIGN KEY (commission_id) REFERENCES affiliate_commissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
