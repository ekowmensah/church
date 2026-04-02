-- 2026_03_21_0102_standardize_emergency_contact_relationships.sql
-- Purpose: controlled relationship catalog + CRN/member linkage support.

CREATE TABLE IF NOT EXISTS relationship_types (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_relationship_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO relationship_types (name, sort_order) VALUES
('Husband', 1),
('Wife', 2),
('Son', 3),
('Daughter', 4),
('Mother', 5),
('Father', 6),
('Brother', 7),
('Sister', 8),
('Uncle', 9),
('Auntie', 10),
('Grandfather', 11),
('Grandmother', 12),
('Other', 99)
ON DUPLICATE KEY UPDATE name = VALUES(name);

ALTER TABLE member_emergency_contacts
    ADD COLUMN IF NOT EXISTS relationship_type_id TINYINT UNSIGNED NULL AFTER relationship,
    ADD COLUMN IF NOT EXISTS relationship_other VARCHAR(100) NULL AFTER relationship_type_id,
    ADD COLUMN IF NOT EXISTS contact_member_id INT NULL AFTER contact_crn;

UPDATE member_emergency_contacts mec
JOIN relationship_types rt
    ON LOWER(TRIM(mec.relationship)) = LOWER(TRIM(rt.name))
SET mec.relationship_type_id = rt.id
WHERE mec.relationship_type_id IS NULL;

UPDATE member_emergency_contacts mec
JOIN members m ON m.crn = mec.contact_crn
SET mec.contact_member_id = m.id
WHERE mec.contact_crn IS NOT NULL
  AND mec.contact_crn <> ''
  AND mec.contact_member_id IS NULL;

ALTER TABLE member_emergency_contacts
    ADD INDEX idx_mec_relationship_type_id (relationship_type_id),
    ADD INDEX idx_mec_contact_member_id (contact_member_id);

SET @fk_rt_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'member_emergency_contacts'
      AND CONSTRAINT_NAME = 'fk_mec_relationship_type'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_rt := IF(
    @fk_rt_exists = 0,
    'ALTER TABLE member_emergency_contacts ADD CONSTRAINT fk_mec_relationship_type FOREIGN KEY (relationship_type_id) REFERENCES relationship_types(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE stmt_fk_rt FROM @sql_fk_rt;
EXECUTE stmt_fk_rt;
DEALLOCATE PREPARE stmt_fk_rt;

SET @fk_cm_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'member_emergency_contacts'
      AND CONSTRAINT_NAME = 'fk_mec_contact_member'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_cm := IF(
    @fk_cm_exists = 0,
    'ALTER TABLE member_emergency_contacts ADD CONSTRAINT fk_mec_contact_member FOREIGN KEY (contact_member_id) REFERENCES members(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE stmt_fk_cm FROM @sql_fk_cm;
EXECUTE stmt_fk_cm;
DEALLOCATE PREPARE stmt_fk_cm;
