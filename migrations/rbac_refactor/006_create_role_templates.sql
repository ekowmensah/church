-- ============================================
-- Migration 006: Create Role Templates System
-- Purpose: Enable quick role creation from templates
-- Created: 2025-11-15
-- ============================================

-- Step 1: Create role_templates table
CREATE TABLE IF NOT EXISTS role_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    template_data JSON NOT NULL COMMENT 'Permissions and settings as JSON',
    category ENUM('church', 'admin', 'ministry', 'custom') DEFAULT 'custom',
    is_system BOOLEAN DEFAULT FALSE COMMENT 'System templates cannot be deleted',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_category (category),
    INDEX idx_system (is_system),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Role templates for quick role creation';

-- Step 2: Populate with common role templates

-- Template 1: Church Administrator
INSERT INTO role_templates (name, description, template_data, category, is_system) VALUES
('Church Administrator', 
 'Full church management access including members, payments, attendance, and reports',
 JSON_OBJECT(
     'permissions', JSON_ARRAY(
         'view_dashboard', 'view_member', 'create_member', 'edit_member', 'delete_member',
         'view_attendance_list', 'mark_attendance', 'view_payment_list', 'create_payment',
         'view_reports_dashboard', 'view_membership_report', 'view_payment_report',
         'view_bibleclass_list', 'view_organization_list', 'view_event_list'
     ),
     'description', 'Complete church management capabilities'
 ),
 'church', TRUE);

-- Template 2: Cashier
INSERT INTO role_templates (name, description, template_data, category, is_system) VALUES
('Cashier', 
 'Payment collection and basic reporting',
 JSON_OBJECT(
     'permissions', JSON_ARRAY(
         'view_dashboard', 'view_payment_list', 'create_payment', 'edit_payment',
         'view_payment_bulk', 'submit_bulk_payment', 'resend_payment_sms',
         'payment_statistics', 'view_payment_report'
     ),
     'description', 'Handle payments and view payment reports'
 ),
 'church', TRUE);

-- Template 3: Class Leader
INSERT INTO role_templates (name, description, template_data, category, is_system) VALUES
('Class Leader', 
 'Manage own class members and attendance',
 JSON_OBJECT(
     'permissions', JSON_ARRAY(
         'view_dashboard', 'view_member', 'edit_member_in_own_class',
         'view_attendance_list', 'mark_attendance', 'view_bibleclass_list',
         'view_membership_report'
     ),
     'description', 'Manage class members and take attendance'
 ),
 'ministry', TRUE);

-- Template 4: Organizational Leader
INSERT INTO role_templates (name, description, template_data, category, is_system) VALUES
('Organizational Leader', 
 'Manage organization members and activities',
 JSON_OBJECT(
     'permissions', JSON_ARRAY(
         'view_dashboard', 'view_member', 'view_organization_list',
         'view_organization_membership_approvals', 'approve_organization_memberships',
         'view_event_list', 'create_event', 'view_report_for_own_org'
     ),
     'description', 'Manage organization and approve memberships'
 ),
 'ministry', TRUE);

-- Template 5: Health Coordinator
INSERT INTO role_templates (name, description, template_data, category, is_system) VALUES
('Health Coordinator', 
 'Manage health records and reports',
 JSON_OBJECT(
     'permissions', JSON_ARRAY(
         'view_dashboard', 'view_health_list', 'create_health_record',
         'edit_health_record', 'view_health_records', 'view_health_report',
         'export_health_report', 'view_member'
     ),
     'description', 'Complete health records management'
 ),
 'ministry', TRUE);

-- Template 6: Steward
INSERT INTO role_templates (name, description, template_data, category, is_system) VALUES
('Steward', 
 'Financial oversight and reporting',
 JSON_OBJECT(
     'permissions', JSON_ARRAY(
         'view_dashboard', 'view_payment_list', 'view_payment_history',
         'view_payment_report', 'export_payment_report', 'view_payment_reversal_log',
         'view_reports_dashboard', 'view_audit_report', 'payment_statistics'
     ),
     'description', 'Financial oversight and audit capabilities'
 ),
 'church', TRUE);

-- Template 7: Sunday School Teacher
INSERT INTO role_templates (name, description, template_data, category, is_system) VALUES
('Sunday School Teacher', 
 'Manage Sunday School students',
 JSON_OBJECT(
     'permissions', JSON_ARRAY(
         'view_dashboard', 'view_sundayschool_list', 'create_sundayschool',
         'edit_sundayschool', 'view_attendance_list', 'mark_attendance',
         'view_payment_list', 'create_payment'
     ),
     'description', 'Manage Sunday School students and activities'
 ),
 'ministry', TRUE);

-- Template 8: Event Coordinator
INSERT INTO role_templates (name, description, template_data, category, is_system) VALUES
('Event Coordinator', 
 'Manage church events and registrations',
 JSON_OBJECT(
     'permissions', JSON_ARRAY(
         'view_dashboard', 'view_event_list', 'create_event', 'edit_event',
         'delete_event', 'view_event_registration_list', 'export_event',
         'view_member', 'send_sms'
     ),
     'description', 'Complete event management capabilities'
 ),
 'ministry', TRUE);

-- Template 9: Visitor Coordinator
INSERT INTO role_templates (name, description, template_data, category, is_system) VALUES
('Visitor Coordinator', 
 'Manage visitors and follow-ups',
 JSON_OBJECT(
     'permissions', JSON_ARRAY(
         'view_dashboard', 'view_visitor_list', 'create_visitor', 'edit_visitor',
         'convert_visitor', 'send_visitor_sms', 'view_visitor_report',
         'export_visitor'
     ),
     'description', 'Manage visitors and conversions'
 ),
 'ministry', TRUE);

-- Template 10: Statistician
INSERT INTO role_templates (name, description, template_data, category, is_system) VALUES
('Statistician', 
 'View all reports and statistics',
 JSON_OBJECT(
     'permissions', JSON_ARRAY(
         'view_dashboard', 'view_reports_dashboard', 'view_membership_report',
         'view_payment_report', 'view_attendance_report', 'view_health_report',
         'view_visitor_report', 'export_membership_report', 'export_payment_report',
         'export_attendance_report', 'view_audit_report'
     ),
     'description', 'Access to all reports and statistics'
 ),
 'church', TRUE);

-- Step 3: Create template usage tracking table
CREATE TABLE IF NOT EXISTS role_template_usage (
    id INT PRIMARY KEY AUTO_INCREMENT,
    template_id INT NOT NULL,
    role_id INT NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES role_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_template (template_id),
    INDEX idx_role (role_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks which roles were created from which templates';

-- ===== VALIDATION QUERIES =====

-- Show all templates
SELECT 
    id,
    name,
    category,
    is_system,
    JSON_LENGTH(JSON_EXTRACT(template_data, '$.permissions')) as permission_count,
    description
FROM role_templates
ORDER BY category, name;

-- Show templates by category
SELECT 
    category,
    COUNT(*) as template_count,
    GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') as templates
FROM role_templates
GROUP BY category
ORDER BY category;

-- Show permission distribution in templates
SELECT 
    rt.name as template_name,
    rt.category,
    JSON_LENGTH(JSON_EXTRACT(rt.template_data, '$.permissions')) as permission_count
FROM role_templates rt
ORDER BY permission_count DESC;

-- Summary
SELECT 
    'Total Templates' as metric,
    COUNT(*) as value
FROM role_templates
UNION ALL
SELECT 
    'System Templates',
    COUNT(*)
FROM role_templates
WHERE is_system = TRUE
UNION ALL
SELECT 
    'Church Templates',
    COUNT(*)
FROM role_templates
WHERE category = 'church'
UNION ALL
SELECT 
    'Ministry Templates',
    COUNT(*)
FROM role_templates
WHERE category = 'ministry';
