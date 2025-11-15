-- ============================================
-- Migration 005: Create Comprehensive Audit System
-- Purpose: Enhanced audit logging for all permission activities
-- Created: 2025-11-15
-- ============================================

-- Step 1: Create new comprehensive audit log table
CREATE TABLE IF NOT EXISTS permission_audit_log_enhanced (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    actor_user_id INT NULL COMMENT 'User who performed the action',
    action ENUM('grant', 'revoke', 'deny', 'check', 'request', 'approve', 'reject', 'modify') NOT NULL,
    target_type ENUM('role', 'user', 'permission', 'category') NOT NULL,
    target_id INT NOT NULL COMMENT 'ID of the target (role_id, user_id, etc.)',
    permission_id INT NULL COMMENT 'Permission involved',
    role_id INT NULL COMMENT 'Role involved',
    old_value JSON NULL COMMENT 'Previous state',
    new_value JSON NULL COMMENT 'New state',
    context JSON NULL COMMENT 'Request context (IP, user agent, etc.)',
    result ENUM('success', 'failure', 'pending') DEFAULT 'success',
    reason TEXT NULL COMMENT 'Reason for action or failure',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE SET NULL,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL,
    INDEX idx_actor (actor_user_id),
    INDEX idx_target (target_type, target_id),
    INDEX idx_permission (permission_id),
    INDEX idx_role (role_id),
    INDEX idx_created (created_at),
    INDEX idx_action (action),
    INDEX idx_result (result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Comprehensive audit log for all permission-related activities';

-- Step 2: Migrate existing audit data from old table
-- Only migrate records with valid user IDs
INSERT INTO permission_audit_log_enhanced 
    (actor_user_id, action, target_type, target_id, permission_id, result, reason, created_at)
SELECT 
    CASE 
        WHEN pal.actor_user_id IS NOT NULL AND EXISTS (SELECT 1 FROM users WHERE id = pal.actor_user_id)
        THEN pal.actor_user_id
        ELSE NULL
    END as actor_user_id,
    pal.action,
    pal.target_type,
    pal.target_id,
    pal.permission_id,
    CASE 
        WHEN pal.details LIKE '%denied%' THEN 'failure'
        WHEN pal.details LIKE '%approved%' THEN 'success'
        ELSE 'success'
    END as result,
    pal.details as reason,
    pal.timestamp as created_at
FROM permission_audit_log pal
WHERE pal.timestamp IS NOT NULL
ORDER BY pal.timestamp;

-- Step 3: Create audit log cleanup procedure
-- Note: Stored procedures and events will be created separately via admin panel
-- For now, manual cleanup can be done with:
-- DELETE FROM permission_audit_log_enhanced WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) AND action = 'check';

-- Step 5: Create audit summary views for easy reporting

-- View: Daily audit summary
CREATE OR REPLACE VIEW v_audit_daily_summary AS
SELECT 
    DATE(created_at) as audit_date,
    action,
    target_type,
    COUNT(*) as action_count,
    COUNT(DISTINCT actor_user_id) as unique_users,
    SUM(CASE WHEN result = 'failure' THEN 1 ELSE 0 END) as failures
FROM permission_audit_log_enhanced
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at), action, target_type
ORDER BY audit_date DESC, action_count DESC;

-- View: User activity summary
CREATE OR REPLACE VIEW v_audit_user_activity AS
SELECT 
    u.id as user_id,
    u.name as user_name,
    u.email,
    COUNT(pal.id) as total_actions,
    COUNT(DISTINCT DATE(pal.created_at)) as active_days,
    MAX(pal.created_at) as last_activity,
    SUM(CASE WHEN pal.action = 'grant' THEN 1 ELSE 0 END) as grants,
    SUM(CASE WHEN pal.action = 'revoke' THEN 1 ELSE 0 END) as revokes,
    SUM(CASE WHEN pal.result = 'failure' THEN 1 ELSE 0 END) as failures
FROM users u
LEFT JOIN permission_audit_log_enhanced pal ON u.id = pal.actor_user_id
WHERE pal.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY u.id, u.name, u.email
ORDER BY total_actions DESC;

-- View: Permission usage statistics
CREATE OR REPLACE VIEW v_audit_permission_usage AS
SELECT 
    p.id as permission_id,
    p.name as permission_name,
    pc.name as category,
    COUNT(pal.id) as check_count,
    SUM(CASE WHEN pal.result = 'success' THEN 1 ELSE 0 END) as successful_checks,
    SUM(CASE WHEN pal.result = 'failure' THEN 1 ELSE 0 END) as failed_checks,
    COUNT(DISTINCT pal.actor_user_id) as unique_users,
    MAX(pal.created_at) as last_checked
FROM permissions p
LEFT JOIN permission_categories pc ON p.category_id = pc.id
LEFT JOIN permission_audit_log_enhanced pal ON p.id = pal.permission_id
WHERE pal.action = 'check' AND pal.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY p.id, p.name, pc.name
ORDER BY check_count DESC;

-- Step 6: Rename old audit table (keep for reference)
RENAME TABLE permission_audit_log TO permission_audit_log_old;

-- Step 7: Create alias view for backward compatibility
CREATE OR REPLACE VIEW permission_audit_log AS
SELECT 
    id,
    actor_user_id,
    action,
    target_type,
    target_id,
    permission_id,
    created_at as timestamp,
    reason as details
FROM permission_audit_log_enhanced;

-- ===== VALIDATION QUERIES =====

-- Show audit log summary
SELECT 
    'Total Audit Entries' as metric,
    COUNT(*) as value
FROM permission_audit_log_enhanced
UNION ALL
SELECT 
    'Entries Last 24 Hours',
    COUNT(*)
FROM permission_audit_log_enhanced
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
UNION ALL
SELECT 
    'Unique Users Logged',
    COUNT(DISTINCT actor_user_id)
FROM permission_audit_log_enhanced
UNION ALL
SELECT 
    'Failed Actions',
    COUNT(*)
FROM permission_audit_log_enhanced
WHERE result = 'failure';

-- Show action distribution
SELECT 
    action,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM permission_audit_log_enhanced), 2) as percentage
FROM permission_audit_log_enhanced
GROUP BY action
ORDER BY count DESC;

-- Show most active users (last 7 days)
SELECT 
    u.name as user_name,
    COUNT(pal.id) as actions,
    MAX(pal.created_at) as last_activity
FROM permission_audit_log_enhanced pal
JOIN users u ON pal.actor_user_id = u.id
WHERE pal.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY u.id, u.name
ORDER BY actions DESC
LIMIT 10;
