-- The email log is filtered by date range and shown newest first.
ALTER TABLE email_log ADD KEY idx_email_created (created_at);
