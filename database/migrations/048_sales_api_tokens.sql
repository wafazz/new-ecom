-- Bearer tokens for GET /api/v1/sales-summary (read-only sales totals for CodexLure).
-- Only a SHA-256 hash of each token is stored; the plain token is shown once on /admin/sales-api-tokens.
CREATE TABLE IF NOT EXISTS sales_api_tokens (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label        VARCHAR(100) NOT NULL,
    token_hash   CHAR(64) NOT NULL,
    token_last4  CHAR(4) NOT NULL,
    created_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    revoked_at   TIMESTAMP NULL,
    UNIQUE KEY uq_sales_api_tokens_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
