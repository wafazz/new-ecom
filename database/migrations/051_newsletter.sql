-- Newsletter sign-ups. Double opt-in: a sign-up stays 'pending' until the address confirms from the emailed link.
-- The link carries only a hash of its token, and expires. Unsubscribing sets status 'unsubscribed'.
CREATE TABLE newsletter_subscribers (
    email          VARCHAR(190) NOT NULL PRIMARY KEY,
    status         ENUM('pending','active','unsubscribed') NOT NULL DEFAULT 'pending',
    token_hash     CHAR(64)     NULL,
    token_expires  DATETIME     NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at   DATETIME     NULL,
    KEY idx_newsletter_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
