-- Stock count sheets. The counter fills counted quantities without seeing the system figure. system_qty is kept
-- from when the count was made. A line is applied once; applying again changes nothing.
CREATE TABLE stock_counts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(120) NOT NULL,
    scope_label  VARCHAR(120) NOT NULL,
    status       ENUM('open','applied') NOT NULL DEFAULT 'open',
    created_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at   DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_count_lines (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    count_id     INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    variant_id   INT UNSIGNED NULL,
    label        VARCHAR(190) NOT NULL,
    sku          VARCHAR(60)  NULL,
    system_qty   INT NOT NULL,
    counted_qty  INT NULL,
    applied      TINYINT(1)   NOT NULL DEFAULT 0,
    KEY idx_count_lines_count (count_id),
    CONSTRAINT fk_count_lines_count FOREIGN KEY (count_id) REFERENCES stock_counts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
