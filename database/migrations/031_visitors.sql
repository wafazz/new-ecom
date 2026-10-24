-- Unique visitors. A visitor is a browser (random cookie); only a SHA-256 hash of the cookie is stored, never the cookie or the IP.
CREATE TABLE visitors (
    visitor_hash CHAR(64)   NOT NULL PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    first_seen   DATETIME   NOT NULL,
    last_seen    DATETIME   NOT NULL,
    KEY idx_visitors_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visitor_days (
    day          DATE       NOT NULL,
    visitor_hash CHAR(64)   NOT NULL,
    PRIMARY KEY (day, visitor_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
