-- Migration: Assign all permissions to Super Admin role (role_id=1) and assign Super Admin role to user_id=3

-- 1. Grant all permissions to Super Admin role
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions;

-- 2. Assign Super Admin role to user_id=3
INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (3, 1);
