CREATE TABLE settings (
    `key`      VARCHAR(100) NOT NULL PRIMARY KEY,
    `value`    TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (`key`, `value`) VALUES
    ('guest_checkout_enabled', '1'),
    ('affiliate_cookie_days', '30');
