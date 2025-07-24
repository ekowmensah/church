-- Migration: Add password_hash to users table, remove class_id/church_id if present
ALTER TABLE users
  ADD COLUMN password_hash VARCHAR(255) NOT NULL AFTER phone;

-- If you previously added class_id or church_id, you can drop them:
-- ALTER TABLE users DROP COLUMN class_id;
-- ALTER TABLE users DROP COLUMN church_id;

-- To roll back:
-- ALTER TABLE users DROP COLUMN password_hash;
