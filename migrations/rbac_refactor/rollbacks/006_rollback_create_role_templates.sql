-- ============================================
-- Rollback 006: Remove Role Templates System
-- Purpose: Rollback role templates
-- Created: 2025-11-15
-- ============================================

-- Step 1: Drop template usage tracking table
DROP TABLE IF EXISTS role_template_usage;

-- Step 2: Drop role templates table
DROP TABLE IF EXISTS role_templates;

-- Validation
SELECT 'Rollback 006 completed successfully' as status;
