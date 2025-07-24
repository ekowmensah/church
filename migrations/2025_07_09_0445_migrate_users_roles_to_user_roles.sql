-- Migration: Move all role_id assignments from users table to user_roles table
-- 1. Insert into user_roles for every user with a non-null role_id
INSERT INTO user_roles (user_id, role_id)
SELECT id, role_id FROM users WHERE role_id IS NOT NULL;

-- 2. (Optional) Remove role_id column from users table (uncomment to drop)
-- ALTER TABLE users DROP COLUMN role_id;

-- You may want to backup your users table before running the last statement.
