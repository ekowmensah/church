-- ============================================
-- Migration 001: Create Permission Categories
-- Purpose: Create categories table and migrate existing permission groups
-- Created: 2025-11-15
-- ============================================

-- Step 1: Create permission_categories table
CREATE TABLE IF NOT EXISTS permission_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    icon VARCHAR(50) COMMENT 'Font Awesome icon class',
    parent_id INT NULL COMMENT 'For nested categories',
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES permission_categories(id) ON DELETE SET NULL,
    INDEX idx_parent (parent_id),
    INDEX idx_active_sort (is_active, sort_order),
    INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Permission categories for organizing permissions';

-- Step 2: Populate categories from existing permission groups
INSERT INTO permission_categories (name, slug, description, icon, sort_order) VALUES
('Dashboard', 'dashboard', 'Dashboard and overview permissions', 'fas fa-tachometer-alt', 1),
('Members', 'members', 'Member management permissions', 'fas fa-users', 2),
('Attendance', 'attendance', 'Attendance tracking permissions', 'fas fa-calendar-check', 3),
('Payments', 'payments', 'Payment and financial permissions', 'fas fa-credit-card', 4),
('Reports', 'reports', 'Reporting and analytics permissions', 'fas fa-chart-line', 5),
('Bible Classes', 'bible-classes', 'Bible class management permissions', 'fas fa-book-open', 6),
('Class Groups', 'class-groups', 'Class group management permissions', 'fas fa-layer-group', 7),
('Organizations', 'organizations', 'Organization management permissions', 'fas fa-building', 8),
('Events', 'events', 'Event management permissions', 'fas fa-calendar-alt', 9),
('Feedback', 'feedback', 'Feedback and communication permissions', 'fas fa-comments', 10),
('Health', 'health', 'Health records permissions', 'fas fa-heartbeat', 11),
('SMS', 'sms', 'SMS and messaging permissions', 'fas fa-sms', 12),
('Visitors', 'visitors', 'Visitor management permissions', 'fas fa-user-plus', 13),
('Sunday School', 'sunday-school', 'Sunday School permissions', 'fas fa-child', 14),
('Transfers', 'transfers', 'Member transfer permissions', 'fas fa-exchange-alt', 15),
('Roles & Permissions', 'roles-permissions', 'Role and permission management', 'fas fa-key', 16),
('Audit & Logs', 'audit-logs', 'Audit trail and logging permissions', 'fas fa-history', 17),
('User Management', 'user-management', 'User account management permissions', 'fas fa-user-cog', 18),
('AJAX/API', 'ajax-api', 'AJAX and API access permissions', 'fas fa-code', 19),
('Bulk Operations', 'bulk', 'Bulk operation permissions', 'fas fa-tasks', 20),
('Advanced', 'advanced', 'Advanced and contextual permissions', 'fas fa-cogs', 21),
('System', 'system', 'System administration permissions', 'fas fa-server', 22);

-- Step 3: Add category_id column to permissions table
ALTER TABLE permissions 
ADD COLUMN category_id INT NULL AFTER `group`,
ADD FOREIGN KEY fk_permission_category (category_id) REFERENCES permission_categories(id) ON DELETE SET NULL,
ADD INDEX idx_category (category_id);

-- Step 4: Migrate existing group values to category_id
-- Dashboard
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'dashboard'
SET p.category_id = pc.id
WHERE p.`group` = 'Dashboard';

-- Members
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'members'
SET p.category_id = pc.id
WHERE p.`group` = 'Members';

-- Attendance
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'attendance'
SET p.category_id = pc.id
WHERE p.`group` = 'Attendance';

-- Payments
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'payments'
SET p.category_id = pc.id
WHERE p.`group` = 'Payments';

-- Reports
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'reports'
SET p.category_id = pc.id
WHERE p.`group` = 'Reports';

-- Bible Classes
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'bible-classes'
SET p.category_id = pc.id
WHERE p.`group` = 'Bible Classes';

-- Class Groups
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'class-groups'
SET p.category_id = pc.id
WHERE p.`group` = 'Class Groups';

-- Organizations
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'organizations'
SET p.category_id = pc.id
WHERE p.`group` = 'Organizations';

-- Events
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'events'
SET p.category_id = pc.id
WHERE p.`group` = 'Events';

-- Feedback
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'feedback'
SET p.category_id = pc.id
WHERE p.`group` = 'Feedback';

-- Health
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'health'
SET p.category_id = pc.id
WHERE p.`group` = 'Health';

-- SMS
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'sms'
SET p.category_id = pc.id
WHERE p.`group` = 'SMS';

-- Visitors
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'visitors'
SET p.category_id = pc.id
WHERE p.`group` = 'Visitors';

-- Sunday School
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'sunday-school'
SET p.category_id = pc.id
WHERE p.`group` = 'Sunday School';

-- Transfers
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'transfers'
SET p.category_id = pc.id
WHERE p.`group` = 'Transfers';

-- Roles & Permissions
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'roles-permissions'
SET p.category_id = pc.id
WHERE p.`group` = 'Roles & Permissions';

-- Audit & Logs
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'audit-logs'
SET p.category_id = pc.id
WHERE p.`group` = 'Audit & Logs';

-- User Management
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'user-management'
SET p.category_id = pc.id
WHERE p.`group` = 'User Management';

-- AJAX/API
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'ajax-api'
SET p.category_id = pc.id
WHERE p.`group` = 'AJAX/API';

-- Bulk Operations
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'bulk'
SET p.category_id = pc.id
WHERE p.`group` = 'Bulk';

-- Advanced
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'advanced'
SET p.category_id = pc.id
WHERE p.`group` = 'Advanced';

-- System
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'system'
SET p.category_id = pc.id
WHERE p.`group` = 'System';

-- Handle NULL groups (assign to System category)
UPDATE permissions p
JOIN permission_categories pc ON pc.slug = 'system'
SET p.category_id = pc.id
WHERE p.`group` IS NULL;

-- Step 5: Validation - Check if all permissions have categories
-- This will show any permissions without categories
SELECT 
    COUNT(*) as uncategorized_count,
    GROUP_CONCAT(id SEPARATOR ', ') as permission_ids
FROM permissions 
WHERE category_id IS NULL;

-- Show category distribution
SELECT 
    pc.name as category,
    COUNT(p.id) as permission_count
FROM permission_categories pc
LEFT JOIN permissions p ON pc.id = p.category_id
GROUP BY pc.id, pc.name
ORDER BY pc.sort_order;
