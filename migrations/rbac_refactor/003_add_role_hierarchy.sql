-- ============================================
-- Migration 003: Add Role Hierarchy
-- Purpose: Enable role inheritance and hierarchy
-- Created: 2025-11-15
-- ============================================

-- Step 1: Add hierarchy and metadata columns to roles table
ALTER TABLE roles
ADD COLUMN parent_id INT NULL COMMENT 'Parent role for inheritance' AFTER description,
ADD COLUMN level INT DEFAULT 0 COMMENT 'Hierarchy level, 0 = top' AFTER parent_id,
ADD COLUMN is_system BOOLEAN DEFAULT FALSE COMMENT 'System roles cannot be deleted' AFTER level,
ADD COLUMN is_active BOOLEAN DEFAULT TRUE COMMENT 'Active/inactive status' AFTER is_system,
ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER is_active,
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Step 2: Add foreign key for parent_id (self-referencing)
ALTER TABLE roles
ADD CONSTRAINT fk_role_parent 
FOREIGN KEY (parent_id) REFERENCES roles(id) ON DELETE SET NULL;

-- Step 3: Add indexes
ALTER TABLE roles
ADD INDEX idx_parent (parent_id),
ADD INDEX idx_level (level),
ADD INDEX idx_active (is_active),
ADD INDEX idx_system (is_system);

-- Step 4: Mark existing system roles
-- Super Admin should never be deleted
UPDATE roles SET is_system = TRUE, level = 0
WHERE name = 'Super Admin' OR id = 1;

-- Mark other core roles as system roles
UPDATE roles SET is_system = TRUE
WHERE name IN ('ADMIN', 'STEWARDS', 'Cashier', 'HEALTH');

-- Step 5: Set up role hierarchy
-- Define a simple hierarchy: Super Admin > Admin > Other Roles

-- Get Super Admin ID
SET @super_admin_id = (SELECT id FROM roles WHERE name = 'Super Admin' LIMIT 1);

-- Get Admin ID
SET @admin_id = (SELECT id FROM roles WHERE name = 'ADMIN' LIMIT 1);

-- Set Admin as child of Super Admin
UPDATE roles 
SET parent_id = @super_admin_id, level = 1
WHERE name = 'ADMIN';

-- Set other administrative roles as children of Admin
UPDATE roles 
SET parent_id = @admin_id, level = 2
WHERE name IN ('STEWARDS', 'Statistician');

-- Set operational roles at level 2 (no parent, independent)
UPDATE roles 
SET parent_id = NULL, level = 2
WHERE name IN ('Rev. Ministers', 'Class Leader', 'Organizational Leader', 'Cashier', 'HEALTH', 'SUNDAY SCHOOL');

-- Step 6: Validation queries

-- Show role hierarchy
SELECT 
    r.id,
    r.name,
    r.level,
    COALESCE(p.name, 'None') as parent_role,
    r.is_system,
    r.is_active
FROM roles r
LEFT JOIN roles p ON r.parent_id = p.id
ORDER BY r.level, r.name;

-- Count roles by level
SELECT 
    level,
    COUNT(*) as role_count,
    GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') as roles
FROM roles
GROUP BY level
ORDER BY level;

-- Show system roles
SELECT 
    name,
    level,
    is_system
FROM roles
WHERE is_system = TRUE
ORDER BY level, name;

-- Summary
SELECT 
    'Total Roles' as metric,
    COUNT(*) as value
FROM roles
UNION ALL
SELECT 
    'System Roles',
    COUNT(*)
FROM roles
WHERE is_system = TRUE
UNION ALL
SELECT 
    'Active Roles',
    COUNT(*)
FROM roles
WHERE is_active = TRUE
UNION ALL
SELECT 
    'Roles with Parents',
    COUNT(*)
FROM roles
WHERE parent_id IS NOT NULL;
