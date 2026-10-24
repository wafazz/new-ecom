CREATE TABLE rate_hits (
    id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(40) NOT NULL,
    ip     VARCHAR(45) NOT NULL,
    hit_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rate (bucket, ip, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (`key`, `value`) VALUES ('shipping_flat', '0.00');
