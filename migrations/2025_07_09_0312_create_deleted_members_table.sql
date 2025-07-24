-- Migration: Create deleted_members table to archive deleted members
CREATE TABLE deleted_members LIKE members;
ALTER TABLE deleted_members ADD COLUMN deleted_at DATETIME NULL AFTER status;
-- Add index for audit
ALTER TABLE deleted_members ADD INDEX idx_deleted_at (deleted_at);
