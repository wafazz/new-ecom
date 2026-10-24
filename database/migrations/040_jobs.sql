-- Background jobs: emails and WhatsApp messages that are sent by bin/worker.php instead of during a web request.
-- Payloads hold customer addresses and message text, so finished jobs are removed after 7 days and failed ones after 30.
CREATE TABLE jobs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type         VARCHAR(60)  NOT NULL,
    payload      MEDIUMTEXT   NOT NULL,
    status       ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
    attempts     INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    run_at       DATETIME     NOT NULL,
    locked_at    DATETIME     NULL,
    last_error   VARCHAR(255) NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_jobs_due (status, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
