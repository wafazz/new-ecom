-- A superadmin is an admin that can also switch modules on and off (Superadmin > Modules).
-- Plain admins can run the back office but cannot see or change module switches.
ALTER TABLE users MODIFY role ENUM('admin','staff','member','superadmin') NOT NULL DEFAULT 'member';
