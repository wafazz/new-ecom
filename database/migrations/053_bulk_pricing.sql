-- Bulk price changes. A batch is one percentage change applied to a set of products (all, a category or a brand).
-- Each changed retail price is stored with its old value, so the whole batch can be undone while prices still match.
CREATE TABLE price_batches (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope_label    VARCHAR(120) NOT NULL,
    percent        DECIMAL(6,2) NOT NULL,
    item_count     INT UNSIGNED NOT NULL DEFAULT 0,
    actor_id       INT UNSIGNED NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    undone_at      DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE price_changes (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id     INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    variant_id   INT UNSIGNED NULL,
    old_price    DECIMAL(10,2) NOT NULL,
    new_price    DECIMAL(10,2) NOT NULL,
    KEY idx_price_changes_batch (batch_id),
    CONSTRAINT fk_price_changes_batch FOREIGN KEY (batch_id) REFERENCES price_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
