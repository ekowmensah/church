-- rollback: 2026_03_21_0102_standardize_emergency_contact_relationships.sql

SET @fk_rt_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'member_emergency_contacts'
      AND CONSTRAINT_NAME = 'fk_mec_relationship_type'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_rt := IF(
    @fk_rt_exists > 0,
    'ALTER TABLE member_emergency_contacts DROP FOREIGN KEY fk_mec_relationship_type',
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
    @fk_cm_exists > 0,
    'ALTER TABLE member_emergency_contacts DROP FOREIGN KEY fk_mec_contact_member',
    'SELECT 1'
);
PREPARE stmt_fk_cm FROM @sql_fk_cm;
EXECUTE stmt_fk_cm;
DEALLOCATE PREPARE stmt_fk_cm;

ALTER TABLE member_emergency_contacts
    DROP INDEX idx_mec_relationship_type_id,
    DROP INDEX idx_mec_contact_member_id;

ALTER TABLE member_emergency_contacts
    DROP COLUMN relationship_type_id,
    DROP COLUMN relationship_other,
    DROP COLUMN contact_member_id;

DROP TABLE IF EXISTS relationship_types;
