-- ============================================
-- Rollback 004: Remove Relationship Enhancements
-- Purpose: Rollback enhanced relationship tables
-- Created: 2025-11-15
-- ============================================

-- ===== PART 1: Rollback role_permissions =====

-- Remove foreign key
ALTER TABLE role_permissions
DROP FOREIGN KEY fk_rp_granted_by;

-- Remove indexes
ALTER TABLE role_permissions
DROP INDEX idx_granted_by,
DROP INDEX idx_granted_at,
DROP INDEX idx_expires,
DROP INDEX idx_rp_active;

-- Remove added columns (keep id as it's now PRIMARY KEY)
ALTER TABLE role_permissions
DROP COLUMN granted_by,
DROP COLUMN granted_at,
DROP COLUMN expires_at,
DROP COLUMN conditions,
DROP COLUMN is_active,
DROP COLUMN id;

-- ===== PART 2: Rollback user_roles =====

-- Remove foreign key
ALTER TABLE user_roles
DROP FOREIGN KEY fk_ur_assigned_by;

-- Remove indexes
ALTER TABLE user_roles
DROP INDEX idx_assigned_by,
DROP INDEX idx_assigned_at,
DROP INDEX idx_expires,
DROP INDEX idx_primary,
DROP INDEX idx_ur_active;

-- Remove added columns (keep id as it's now PRIMARY KEY)
ALTER TABLE user_roles
DROP COLUMN assigned_by,
DROP COLUMN assigned_at,
DROP COLUMN expires_at,
DROP COLUMN is_primary,
DROP COLUMN is_active,
DROP COLUMN id;

-- Validation
SELECT 'Rollback 004 completed successfully' as status;
