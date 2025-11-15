-- ============================================
-- Rollback 005: Remove Comprehensive Audit System
-- Purpose: Rollback audit system changes
-- Created: 2025-11-15
-- ============================================

-- Step 1: Drop views
DROP VIEW IF EXISTS permission_audit_log;
DROP VIEW IF EXISTS v_audit_daily_summary;
DROP VIEW IF EXISTS v_audit_user_activity;
DROP VIEW IF EXISTS v_audit_permission_usage;

-- Step 2: Drop event scheduler
DROP EVENT IF EXISTS monthly_audit_cleanup;

-- Step 3: Drop stored procedure
DROP PROCEDURE IF EXISTS cleanup_audit_logs;

-- Step 4: Restore old audit table name
RENAME TABLE permission_audit_log_old TO permission_audit_log;

-- Step 5: Drop new audit table
DROP TABLE IF EXISTS permission_audit_log_enhanced;

-- Validation
SELECT 'Rollback 005 completed successfully' as status;
