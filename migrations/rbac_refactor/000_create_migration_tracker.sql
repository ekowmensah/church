-- ============================================
-- Migration Tracker System
-- Purpose: Track RBAC refactoring migrations
-- Created: 2025-11-15
-- ============================================

-- Create migration tracking table
CREATE TABLE IF NOT EXISTS rbac_migrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    migration_number VARCHAR(10) NOT NULL UNIQUE,
    migration_name VARCHAR(255) NOT NULL,
    description TEXT,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    executed_by VARCHAR(100),
    execution_time_ms INT,
    status ENUM('pending', 'running', 'completed', 'failed', 'rolled_back') DEFAULT 'pending',
    error_message TEXT NULL,
    rollback_executed_at TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_executed_at (executed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert this migration as the first entry
INSERT INTO rbac_migrations (migration_number, migration_name, description, status, executed_by)
VALUES ('000', 'create_migration_tracker', 'Create migration tracking system', 'completed', 'system');
