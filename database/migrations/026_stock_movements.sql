-- Every change to a stock quantity: manual adjustments from Stock Control, checkout deductions, cancellation restocks.
-- sku / label are snapshots so history still reads well after a variation is renamed or removed.
CREATE TABLE stock_movements (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    variant_id INT UNSIGNED NULL,
    sku        VARCHAR(60) NULL,
    label      VARCHAR(190) NULL,
    change_qty INT NOT NULL,
    qty_before INT NOT NULL,
    qty_after  INT NOT NULL,
    reason     VARCHAR(20) NOT NULL,
    note       VARCHAR(255) NULL,
    order_id   INT UNSIGNED NULL,
    actor_id   INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_stock_item (product_id, variant_id, id),
    KEY idx_stock_created (created_at),
    CONSTRAINT fk_stock_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
