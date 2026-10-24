CREATE TABLE login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(190) NOT NULL,
    ip           VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attempts_lookup (email, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
