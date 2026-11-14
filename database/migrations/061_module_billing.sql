-- Module prices, paid periods and invoices. A module with a price needs a paid period to stay on.
-- period_end 9999-12-31 means a one-time purchase that never runs out.
CREATE TABLE module_prices (
    module_key   VARCHAR(40)  NOT NULL PRIMARY KEY,
    period       ENUM('one_time','monthly','yearly') NOT NULL DEFAULT 'monthly',
    amount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE module_subscriptions (
    module_key   VARCHAR(40)  NOT NULL PRIMARY KEY,
    paid_until   DATE         NULL,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE module_invoices (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_key        VARCHAR(40)   NOT NULL,
    period            ENUM('one_time','monthly','yearly') NOT NULL,
    amount            DECIMAL(10,2) NOT NULL,
    period_start      DATE          NOT NULL,
    period_end        DATE          NOT NULL,
    status            ENUM('unpaid','pending_review','paid','rejected') NOT NULL DEFAULT 'unpaid',
    method            ENUM('billplz','bank_receipt') NULL,
    billplz_bill_id   VARCHAR(64)   NULL,
    billplz_url       VARCHAR(255)  NULL,
    receipt_path      VARCHAR(255)  NULL,
    note              VARCHAR(255)  NULL,
    requested_by      INT UNSIGNED  NULL,
    reviewed_by       INT UNSIGNED  NULL,
    reviewed_at       DATETIME      NULL,
    paid_at           DATETIME      NULL,
    reminder_sent_at  DATETIME      NULL,
    created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_module_invoices_module (module_key, status),
    UNIQUE KEY uq_module_invoices_bill (billplz_bill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
