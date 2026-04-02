-- 2026_03_21_0105_create_assets_register.sql
-- Purpose: create Assets and Properties Register with condition tracking.

CREATE TABLE IF NOT EXISTS asset_departments (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_code VARCHAR(60) NOT NULL,
    department_id INT NULL,
    item_group VARCHAR(120) NULL,
    item_name VARCHAR(180) NOT NULL,
    purchase_date DATE NULL,
    quantity INT NOT NULL DEFAULT 1,
    receipt_or_serial_number VARCHAR(120) NULL,
    amount DECIMAL(12,2) NULL,
    condition_status ENUM('New', 'Good', 'Fair', 'Poor', 'Under Maintenance', 'Damaged', 'Obsolete', 'Condemned', 'Disposed') NOT NULL,
    allocation_note VARCHAR(180) NULL,
    status ENUM('active', 'disposed') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assets_asset_code (asset_code),
    KEY idx_assets_department (department_id),
    KEY idx_assets_condition_status (condition_status),
    KEY idx_assets_item_name (item_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id BIGINT UNSIGNED NOT NULL,
    from_department_id INT NULL,
    to_department_id INT NULL,
    moved_by INT NULL,
    moved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_asset_movements_asset_id (asset_id),
    KEY idx_asset_movements_moved_at (moved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE assets
    ADD CONSTRAINT fk_assets_department
        FOREIGN KEY (department_id) REFERENCES asset_departments(id)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE asset_movements
    ADD CONSTRAINT fk_asset_movements_asset
        FOREIGN KEY (asset_id) REFERENCES assets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT fk_asset_movements_from_department
        FOREIGN KEY (from_department_id) REFERENCES asset_departments(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT fk_asset_movements_to_department
        FOREIGN KEY (to_department_id) REFERENCES asset_departments(id)
        ON DELETE SET NULL ON UPDATE CASCADE;
