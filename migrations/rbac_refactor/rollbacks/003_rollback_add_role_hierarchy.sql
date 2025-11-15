-- ============================================
-- Rollback 003: Remove Role Hierarchy
-- Purpose: Rollback role hierarchy changes
-- Created: 2025-11-15
-- ============================================

-- Step 1: Remove foreign key constraint
ALTER TABLE roles
DROP FOREIGN KEY fk_role_parent;

-- Step 2: Remove indexes
ALTER TABLE roles
DROP INDEX idx_parent,
DROP INDEX idx_level,
DROP INDEX idx_active,
DROP INDEX idx_system;

-- Step 3: Remove added columns
ALTER TABLE roles
DROP COLUMN parent_id,
DROP COLUMN level,
DROP COLUMN is_system,
DROP COLUMN is_active,
DROP COLUMN created_at,
DROP COLUMN updated_at;

-- Validation
SELECT 'Rollback 003 completed successfully' as status;
