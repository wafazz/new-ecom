-- Members get a public referral code the first time they open their affiliate page.
ALTER TABLE users ADD COLUMN affiliate_code CHAR(8) NULL, ADD UNIQUE KEY uq_users_affiliate_code (affiliate_code);
