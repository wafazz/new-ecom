-- Who changed what. `detail` never holds secrets (only field names / non-sensitive facts).
CREATE TABLE audit_logs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id   INT UNSIGNED NULL,
    action     VARCHAR(60) NOT NULL,
    detail     VARCHAR(255) NULL,
    ip         VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_action (action, id),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
