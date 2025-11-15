-- ============================================
-- Migration 004: Enhance Relationship Tables
-- Purpose: Add metadata and tracking to role_permissions and user_roles
-- Created: 2025-11-15
-- ============================================

-- ===== PART 1: Enhance role_permissions table =====

-- Step 1: Add primary key and metadata columns
ALTER TABLE role_permissions
ADD COLUMN id INT PRIMARY KEY AUTO_INCREMENT FIRST,
ADD COLUMN granted_by INT NULL COMMENT 'User ID who granted this permission' AFTER permission_id,
ADD COLUMN granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER granted_by,
ADD COLUMN expires_at TIMESTAMP NULL COMMENT 'For temporary permissions' AFTER granted_at,
ADD COLUMN conditions JSON NULL COMMENT 'Additional conditions for permission' AFTER expires_at,
ADD COLUMN is_active BOOLEAN DEFAULT TRUE AFTER conditions;

-- Step 2: Add foreign key for granted_by
ALTER TABLE role_permissions
ADD CONSTRAINT fk_rp_granted_by 
FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL;

-- Step 3: Add indexes for role_permissions
ALTER TABLE role_permissions
ADD INDEX idx_granted_by (granted_by),
ADD INDEX idx_granted_at (granted_at),
ADD INDEX idx_expires (expires_at),
ADD INDEX idx_rp_active (is_active);

-- Step 4: Update existing role_permissions with default values
-- Set granted_by to super admin (user_id = 3) for existing records
UPDATE role_permissions SET granted_by = 3 WHERE granted_by IS NULL;

-- ===== PART 2: Enhance user_roles table =====

-- Step 5: Add primary key and metadata columns
ALTER TABLE user_roles
ADD COLUMN id INT PRIMARY KEY AUTO_INCREMENT FIRST,
ADD COLUMN assigned_by INT NULL COMMENT 'User ID who assigned this role' AFTER role_id,
ADD COLUMN assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER assigned_by,
ADD COLUMN expires_at TIMESTAMP NULL COMMENT 'For temporary role assignments' AFTER assigned_at,
ADD COLUMN is_primary BOOLEAN DEFAULT FALSE COMMENT 'Primary role for user' AFTER expires_at,
ADD COLUMN is_active BOOLEAN DEFAULT TRUE AFTER is_primary;

-- Step 6: Add foreign key for assigned_by
ALTER TABLE user_roles
ADD CONSTRAINT fk_ur_assigned_by 
FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL;

-- Step 7: Add indexes for user_roles
ALTER TABLE user_roles
ADD INDEX idx_assigned_by (assigned_by),
ADD INDEX idx_assigned_at (assigned_at),
ADD INDEX idx_expires (expires_at),
ADD INDEX idx_primary (user_id, is_primary),
ADD INDEX idx_ur_active (is_active);

-- Step 8: Update existing user_roles with default values
-- Set assigned_by to super admin (user_id = 3) for existing records
UPDATE user_roles SET assigned_by = 3 WHERE assigned_by IS NULL;

-- Step 9: Set primary role for each user (first role assigned)
-- This ensures each user has exactly one primary role
UPDATE user_roles ur1
JOIN (
    SELECT user_id, MIN(id) as first_role_id
    FROM user_roles
    GROUP BY user_id
) ur2 ON ur1.user_id = ur2.user_id AND ur1.id = ur2.first_role_id
SET ur1.is_primary = TRUE;

-- ===== VALIDATION QUERIES =====

-- Show role_permissions summary
SELECT 
    'Total Role-Permission Assignments' as metric,
    COUNT(*) as value
FROM role_permissions
UNION ALL
SELECT 
    'Active Assignments',
    COUNT(*)
FROM role_permissions
WHERE is_active = TRUE
UNION ALL
SELECT 
    'Temporary Assignments',
    COUNT(*)
FROM role_permissions
WHERE expires_at IS NOT NULL
UNION ALL
SELECT 
    'Assignments with Conditions',
    COUNT(*)
FROM role_permissions
WHERE conditions IS NOT NULL;

-- Show user_roles summary
SELECT 
    'Total User-Role Assignments' as metric,
    COUNT(*) as value
FROM user_roles
UNION ALL
SELECT 
    'Active Assignments',
    COUNT(*)
FROM user_roles
WHERE is_active = TRUE
UNION ALL
SELECT 
    'Primary Role Assignments',
    COUNT(*)
FROM user_roles
WHERE is_primary = TRUE
UNION ALL
SELECT 
    'Temporary Assignments',
    COUNT(*)
FROM user_roles
WHERE expires_at IS NOT NULL;

-- Show users with multiple roles
SELECT 
    u.id as user_id,
    u.name as user_name,
    COUNT(ur.id) as role_count,
    GROUP_CONCAT(r.name ORDER BY ur.is_primary DESC, r.name SEPARATOR ', ') as roles
FROM users u
JOIN user_roles ur ON u.id = ur.user_id
JOIN roles r ON ur.role_id = r.id
WHERE ur.is_active = TRUE
GROUP BY u.id, u.name
HAVING COUNT(ur.id) > 1
ORDER BY role_count DESC;

-- Show most granted permissions
SELECT 
    p.name as permission,
    COUNT(rp.id) as granted_to_roles,
    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') as roles
FROM role_permissions rp
JOIN permissions p ON rp.permission_id = p.id
JOIN roles r ON rp.role_id = r.id
WHERE rp.is_active = TRUE
GROUP BY p.id, p.name
ORDER BY granted_to_roles DESC
LIMIT 10;
