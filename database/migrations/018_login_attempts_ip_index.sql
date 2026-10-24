-- Supports the per-IP login throttle (all emails from one address).
ALTER TABLE login_attempts ADD KEY idx_attempts_ip (ip, attempted_at);
