-- Migration: Split name field, add registration_token to members
ALTER TABLE members 
  ADD COLUMN first_name VARCHAR(100) AFTER id,
  ADD COLUMN middle_name VARCHAR(100) AFTER first_name,
  ADD COLUMN last_name VARCHAR(100) AFTER middle_name,
  ADD COLUMN registration_token VARCHAR(64) AFTER user_id;

-- Migrate existing names (put all into first_name for now, manual cleanup may be needed)
UPDATE members SET first_name = name WHERE name IS NOT NULL;

-- Remove old name column if desired (uncomment after verifying migration)
-- ALTER TABLE members DROP COLUMN name;
