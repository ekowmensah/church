-- Fix payment_intents records with invalid created_at dates
-- Update records with 0000-00-00 00:00:00 to use a reasonable default

UPDATE payment_intents 
SET created_at = COALESCE(updated_at, NOW()) 
WHERE created_at = '0000-00-00 00:00:00' OR created_at IS NULL;

-- If there's no updated_at field, we'll use current timestamp
-- This is a one-time fix for existing records

-- Optional: Add a proper default for the created_at column if it doesn't have one
-- ALTER TABLE payment_intents MODIFY COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
