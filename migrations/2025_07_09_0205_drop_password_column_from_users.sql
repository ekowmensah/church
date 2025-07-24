-- Migration: Drop password column from users table
ALTER TABLE users DROP COLUMN password;

-- To roll back:
-- ALTER TABLE users ADD COLUMN password VARCHAR(255) NULL AFTER password_hash;
