-- ============================================
-- Rollback 002: Remove Permission Enhancements
-- Purpose: Rollback enhanced permissions table changes
-- Created: 2025-11-15
-- ============================================

-- Step 1: Remove foreign key constraint
ALTER TABLE permissions
DROP FOREIGN KEY fk_permission_parent;

-- Step 2: Remove indexes
ALTER TABLE permissions
DROP INDEX idx_parent,
DROP INDEX idx_active,
DROP INDEX idx_type,
DROP INDEX idx_system,
DROP INDEX idx_sort;

-- Step 3: Remove added columns
ALTER TABLE permissions
DROP COLUMN parent_id,
DROP COLUMN permission_type,
DROP COLUMN is_system,
DROP COLUMN requires_context,
DROP COLUMN sort_order,
DROP COLUMN is_active,
DROP COLUMN created_at,
DROP COLUMN updated_at;

-- Validation
SELECT 'Rollback 002 completed successfully' as status;
