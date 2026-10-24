-- Who has already been emailed a voucher code. One row per code and address, so nobody gets the same code twice.
CREATE TABLE voucher_sends (
    voucher_id  INT UNSIGNED NOT NULL,
    email       VARCHAR(190) NOT NULL,
    sent_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (voucher_id, email),
    CONSTRAINT fk_voucher_sends_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
