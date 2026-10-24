-- Addresses that asked not to receive announcements. Order and account emails still go to them.
CREATE TABLE email_unsubscribes (
    email       VARCHAR(190) NOT NULL PRIMARY KEY,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
