-- One message thread per order, between the customer and the shop. sender says who wrote each message.
CREATE TABLE support_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NOT NULL,
    sender      ENUM('customer','staff') NOT NULL,
    staff_id    INT UNSIGNED NULL,
    body        VARCHAR(2000) NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_support_order (order_id, id),
    CONSTRAINT fk_support_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_support_staff FOREIGN KEY (staff_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
