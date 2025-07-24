-- Add 'group' column to permissions table for better organization
ALTER TABLE permissions ADD COLUMN `group` VARCHAR(100) DEFAULT NULL AFTER name;
