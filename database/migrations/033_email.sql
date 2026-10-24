-- Email (Resend): confirmed email address, email confirmation tokens, a log of every email, and announcement history.
ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email;
-- Accounts that already exist are treated as confirmed; only new sign-ups must confirm.
UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL;

CREATE TABLE email_verifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64)     NOT NULL,
    expires_at  DATETIME     NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_verify_token (token_hash),
    KEY idx_verify_user (user_id),
    CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind        VARCHAR(20)  NOT NULL,
    to_email    VARCHAR(190) NOT NULL,
    subject     VARCHAR(255) NULL,
    ok          TINYINT(1)   NOT NULL DEFAULT 0,
    resend_id   VARCHAR(64)  NULL,
    error       VARCHAR(255) NULL,
    order_id    INT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_kind (kind, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_campaigns (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject     VARCHAR(255) NOT NULL,
    audience    VARCHAR(20)  NOT NULL,
    recipients  INT UNSIGNED NOT NULL DEFAULT 0,
    sent        INT UNSIGNED NOT NULL DEFAULT 0,
    failed      INT UNSIGNED NOT NULL DEFAULT 0,
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
