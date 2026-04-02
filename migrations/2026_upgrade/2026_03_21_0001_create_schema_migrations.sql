-- 2026_03_21_0001_create_schema_migrations.sql
-- Purpose: global migration tracking for the full project (beyond RBAC-only tracking)

CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_key VARCHAR(191) NOT NULL,
    migration_name VARCHAR(255) NOT NULL,
    checksum_sha256 CHAR(64) NULL,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    executed_by VARCHAR(120) NULL,
    execution_time_ms INT NULL,
    status ENUM('completed', 'rolled_back', 'failed') NOT NULL DEFAULT 'completed',
    rollback_script VARCHAR(255) NULL,
    notes TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_key (migration_key),
    KEY idx_schema_migrations_status (status),
    KEY idx_schema_migrations_executed_at (executed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
