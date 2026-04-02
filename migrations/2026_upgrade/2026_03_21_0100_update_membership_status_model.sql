-- 2026_03_21_0100_update_membership_status_model.sql
-- Purpose: align membership_status with requested taxonomy:
-- Full Member, Catechumen, Adherent, Junior Member, Distant Member, Invalid

CREATE TABLE IF NOT EXISTS membership_status_migration_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_table ENUM('members', 'deleted_members') NOT NULL,
    source_id INT NOT NULL,
    old_status VARCHAR(64) NULL,
    new_status VARCHAR(64) NULL,
    migrated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_membership_status_migration_audit_source (source_table, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO membership_status_migration_audit (source_table, source_id, old_status, new_status)
SELECT 'members', id, membership_status,
       CASE
           WHEN membership_status = 'Juvenile' THEN 'Junior Member'
           WHEN membership_status = 'Invalid Distant Member' THEN 'Invalid'
           ELSE membership_status
       END
FROM members
WHERE membership_status IN ('Juvenile', 'Invalid Distant Member');

INSERT INTO membership_status_migration_audit (source_table, source_id, old_status, new_status)
SELECT 'deleted_members', id, membership_status,
       CASE
           WHEN membership_status = 'Juvenile' THEN 'Junior Member'
           WHEN membership_status = 'Invalid Distant Member' THEN 'Invalid'
           ELSE membership_status
       END
FROM deleted_members
WHERE membership_status IN ('Juvenile', 'Invalid Distant Member');

UPDATE members
SET membership_status = 'Junior Member'
WHERE membership_status = 'Juvenile';

UPDATE members
SET membership_status = 'Invalid'
WHERE membership_status = 'Invalid Distant Member';

UPDATE deleted_members
SET membership_status = 'Junior Member'
WHERE membership_status = 'Juvenile';

UPDATE deleted_members
SET membership_status = 'Invalid'
WHERE membership_status = 'Invalid Distant Member';

ALTER TABLE members
MODIFY COLUMN membership_status ENUM('Full Member', 'Catechumen', 'Adherent', 'Junior Member', 'Distant Member', 'Invalid') DEFAULT NULL;

ALTER TABLE deleted_members
MODIFY COLUMN membership_status ENUM('Full Member', 'Catechumen', 'Adherent', 'Junior Member', 'Distant Member', 'Invalid') DEFAULT NULL;
