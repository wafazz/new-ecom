-- Every WhatsApp message sent to a customer (status notifications), with the result.
CREATE TABLE whatsapp_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NULL,
    status_key  VARCHAR(20)  NOT NULL,
    phone       VARCHAR(20)  NULL,
    ok          TINYINT(1)   NOT NULL DEFAULT 0,
    message_id  INT UNSIGNED NULL,
    error       VARCHAR(255) NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wa_order (order_id),
    CONSTRAINT fk_wa_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
