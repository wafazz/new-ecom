CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role          ENUM('admin','staff','member') NOT NULL DEFAULT 'member',
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    phone         VARCHAR(30) NULL,
    password_hash VARCHAR(255) NOT NULL,
    permissions   JSON NULL,
    status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
