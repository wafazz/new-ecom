-- A module can offer up to three plans (one-time, monthly, yearly), one row per period. A missing or zero plan is not offered.
ALTER TABLE module_prices DROP PRIMARY KEY, ADD PRIMARY KEY (module_key, period);
-- The period the module was bought with, so renewal reminders follow it.
ALTER TABLE module_subscriptions ADD COLUMN period ENUM('one_time','monthly','yearly') NULL AFTER module_key;
