-- 2026_03_21_0002_create_release_versions.sql
-- Purpose: track app/db release versions and deployment metadata

CREATE TABLE IF NOT EXISTS release_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    app_version VARCHAR(50) NOT NULL,
    db_version VARCHAR(50) NOT NULL,
    release_name VARCHAR(120) NULL,
    git_commit VARCHAR(64) NULL,
    environment ENUM('dev', 'staging', 'production') NOT NULL DEFAULT 'dev',
    deployed_by VARCHAR(120) NULL,
    deployed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_release_versions_deployed_at (deployed_at),
    KEY idx_release_versions_environment (environment),
    KEY idx_release_versions_app_db (app_version, db_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
