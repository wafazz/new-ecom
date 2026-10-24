-- Sign out other sessions: each password change or reset bumps this counter, and a session that was
-- started with an older value is signed out on its next request. Existing accounts start at 0.
ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0;
