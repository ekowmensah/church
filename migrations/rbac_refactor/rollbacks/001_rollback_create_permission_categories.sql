-- ============================================
-- Rollback 001: Remove Permission Categories
-- Purpose: Rollback permission categories migration
-- Created: 2025-11-15
-- ============================================

-- Step 1: Remove foreign key constraint from permissions table
ALTER TABLE permissions 
DROP FOREIGN KEY fk_permission_category;

-- Step 2: Remove category_id column from permissions table
ALTER TABLE permissions 
DROP COLUMN category_id;

-- Step 3: Drop permission_categories table
DROP TABLE IF EXISTS permission_categories;

-- Validation: Verify rollback
SELECT 'Rollback completed successfully' as status;
