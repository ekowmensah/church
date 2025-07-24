-- Migration: Assign all permissions to super admin (user_id=3)
-- 1. Find all permission IDs
-- 2. Insert into user_permissions with allowed=1
INSERT IGNORE INTO user_permissions (user_id, permission_id, allowed)
SELECT 3, id, 1 FROM permissions;
