-- Each superadmin has a 6-digit PIN that is asked for before a module is switched on or off.
-- pin_hash is NULL until the superadmin sets their own; until then the default PIN applies (see SuperadminPin).
ALTER TABLE users
    ADD COLUMN pin_hash VARCHAR(255) NULL,
    ADD COLUMN pin_failed TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN pin_locked_until DATETIME NULL;
