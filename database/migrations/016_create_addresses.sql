-- Member address book. Orders keep their own address snapshot, so editing or deleting a row never changes history.
CREATE TABLE addresses (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    label      VARCHAR(40) NULL,
    name       VARCHAR(120) NOT NULL,
    phone      VARCHAR(30) NOT NULL,
    line1      VARCHAR(190) NOT NULL,
    line2      VARCHAR(190) NULL,
    city       VARCHAR(100) NOT NULL,
    state      VARCHAR(60) NOT NULL,
    postcode   VARCHAR(10) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_addresses_user (user_id),
    CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
