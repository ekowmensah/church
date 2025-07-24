-- Migration: Add phone column to users table
ALTER TABLE users
  ADD COLUMN phone VARCHAR(32) NOT NULL AFTER email,
  ADD UNIQUE KEY uniq_users_phone (phone);

-- If you want to allow NULLs, change NOT NULL to NULL above.
-- If you want to allow duplicate phones, remove the UNIQUE KEY line.

-- To roll back:
-- ALTER TABLE users DROP COLUMN phone, DROP INDEX uniq_users_phone;
