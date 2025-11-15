-- ============================================
-- Migration 002: Enhance Permissions Table
-- Purpose: Add hierarchy, metadata, and system flags to permissions
-- Created: 2025-11-15
-- ============================================

-- Step 1: Add new columns to permissions table
ALTER TABLE permissions
ADD COLUMN parent_id INT NULL COMMENT 'Parent permission for hierarchy' AFTER category_id,
ADD COLUMN permission_type ENUM('action', 'resource', 'feature', 'system') DEFAULT 'action' COMMENT 'Type of permission' AFTER parent_id,
ADD COLUMN is_system BOOLEAN DEFAULT FALSE COMMENT 'System permissions cannot be deleted' AFTER permission_type,
ADD COLUMN requires_context BOOLEAN DEFAULT FALSE COMMENT 'Needs additional context for checking' AFTER is_system,
ADD COLUMN sort_order INT DEFAULT 0 COMMENT 'Display order within category' AFTER requires_context,
ADD COLUMN is_active BOOLEAN DEFAULT TRUE COMMENT 'Active/inactive status' AFTER sort_order,
ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER is_active,
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Step 2: Add foreign key for parent_id (self-referencing)
ALTER TABLE permissions
ADD CONSTRAINT fk_permission_parent 
FOREIGN KEY (parent_id) REFERENCES permissions(id) ON DELETE CASCADE;

-- Step 3: Add indexes for performance
ALTER TABLE permissions
ADD INDEX idx_parent (parent_id),
ADD INDEX idx_active (is_active),
ADD INDEX idx_type (permission_type),
ADD INDEX idx_system (is_system),
ADD INDEX idx_sort (category_id, sort_order);

-- Step 4: Mark core system permissions as is_system = TRUE
-- These are critical permissions that should never be deleted

-- Dashboard permissions
UPDATE permissions SET is_system = TRUE, permission_type = 'feature'
WHERE name IN ('view_dashboard');

-- Core member permissions
UPDATE permissions SET is_system = TRUE, permission_type = 'resource'
WHERE name IN ('view_member', 'create_member', 'edit_member', 'delete_member');

-- Core user management
UPDATE permissions SET is_system = TRUE, permission_type = 'resource'
WHERE name IN ('view_user_list', 'create_user', 'edit_user', 'delete_user');

-- Core role & permission management
UPDATE permissions SET is_system = TRUE, permission_type = 'system'
WHERE name IN ('manage_roles', 'manage_permissions', 'view_role_list', 'view_permission_list');

-- Step 5: Set permission types based on naming patterns
-- Action permissions (create, edit, delete, etc.)
UPDATE permissions SET permission_type = 'action'
WHERE name LIKE 'create_%' 
   OR name LIKE 'edit_%' 
   OR name LIKE 'delete_%'
   OR name LIKE 'view_%'
   OR name LIKE 'export_%'
   OR name LIKE 'import_%'
   OR name LIKE 'upload_%'
   OR name LIKE 'send_%'
   OR name LIKE 'resend_%';

-- Resource permissions (manage entire resource)
UPDATE permissions SET permission_type = 'resource'
WHERE name LIKE 'manage_%';

-- System permissions
UPDATE permissions SET permission_type = 'system'
WHERE name LIKE 'access_admin%'
   OR name LIKE 'backup_%'
   OR name LIKE 'restore_%'
   OR name LIKE 'run_%'
   OR name IN ('manage_settings', 'manage_templates', 'manage_menu_items');

-- Feature permissions (broader features)
UPDATE permissions SET permission_type = 'feature'
WHERE name LIKE '%_dashboard'
   OR name LIKE '%_panel'
   OR name LIKE '%_bulk';

-- Step 6: Mark context-aware permissions
-- These permissions require additional context (e.g., "own church", "own class")
UPDATE permissions SET requires_context = TRUE
WHERE name IN (
    'edit_member_in_own_class',
    'edit_member_in_own_church',
    'view_report_for_own_org',
    'assign_leader_in_own_class',
    'make_payment_for_own_class'
);

-- Step 7: Set sort_order for better organization within categories
-- This will be used for UI display ordering

-- Dashboard (category_id = 1)
UPDATE permissions p
JOIN permission_categories pc ON p.category_id = pc.id
SET p.sort_order = 1
WHERE pc.slug = 'dashboard' AND p.name = 'view_dashboard';

-- Members - ordered by typical workflow
SET @member_order = 0;
UPDATE permissions p
JOIN permission_categories pc ON p.category_id = pc.id
SET p.sort_order = (@member_order := @member_order + 1)
WHERE pc.slug = 'members'
ORDER BY 
    CASE 
        WHEN p.name LIKE 'view_%' THEN 1
        WHEN p.name LIKE 'create_%' THEN 2
        WHEN p.name LIKE 'edit_%' THEN 3
        WHEN p.name LIKE 'delete_%' THEN 4
        WHEN p.name LIKE 'export_%' THEN 5
        WHEN p.name LIKE 'import_%' THEN 6
        ELSE 7
    END,
    p.name;

-- Payments - ordered by typical workflow
SET @payment_order = 0;
UPDATE permissions p
JOIN permission_categories pc ON p.category_id = pc.id
SET p.sort_order = (@payment_order := @payment_order + 1)
WHERE pc.slug = 'payments'
ORDER BY 
    CASE 
        WHEN p.name LIKE 'view_%' THEN 1
        WHEN p.name LIKE 'make_%' THEN 2
        WHEN p.name LIKE 'create_%' THEN 3
        WHEN p.name LIKE 'edit_%' THEN 4
        WHEN p.name LIKE 'delete_%' THEN 5
        WHEN p.name LIKE 'reverse_%' THEN 6
        ELSE 7
    END,
    p.name;

-- Step 8: Create parent-child relationships for related permissions
-- Example: "manage_members" as parent of "create_member", "edit_member", etc.

-- Note: We'll add specific parent-child relationships in a future migration
-- after reviewing the permission structure with the team

-- Step 9: Validation queries
-- Show permissions by type
SELECT 
    permission_type,
    COUNT(*) as count,
    GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') as examples
FROM permissions
GROUP BY permission_type;

-- Show system permissions
SELECT name, permission_type, is_system
FROM permissions
WHERE is_system = TRUE
ORDER BY permission_type, name;

-- Show context-aware permissions
SELECT name, requires_context
FROM permissions
WHERE requires_context = TRUE
ORDER BY name;

-- Show permissions without categories (should be 0)
SELECT COUNT(*) as uncategorized_count
FROM permissions
WHERE category_id IS NULL;

-- Summary
SELECT 
    'Total Permissions' as metric,
    COUNT(*) as value
FROM permissions
UNION ALL
SELECT 
    'System Permissions',
    COUNT(*)
FROM permissions
WHERE is_system = TRUE
UNION ALL
SELECT 
    'Context-Aware Permissions',
    COUNT(*)
FROM permissions
WHERE requires_context = TRUE
UNION ALL
SELECT 
    'Active Permissions',
    COUNT(*)
FROM permissions
WHERE is_active = TRUE;
