-- 2026_03_21_0101_add_member_spouse_marriage_fields.sql
-- Purpose: support spouse linkage, marriage details, occupation, and transfer/removal metadata.

ALTER TABLE members
    ADD COLUMN IF NOT EXISTS spouse_crn VARCHAR(50) NULL AFTER marital_status,
    ADD COLUMN IF NOT EXISTS spouse_name VARCHAR(255) NULL AFTER spouse_crn,
    ADD COLUMN IF NOT EXISTS marriage_type ENUM('Customary', 'Ordinance', 'Blessing', 'Court Registration') NULL AFTER spouse_name,
    ADD COLUMN IF NOT EXISTS occupation VARCHAR(120) NULL AFTER profession,
    ADD COLUMN IF NOT EXISTS transfer_from_other_chapel TINYINT(1) NOT NULL DEFAULT 0 AFTER date_of_enrollment,
    ADD COLUMN IF NOT EXISTS transfer_diocese VARCHAR(150) NULL AFTER transfer_from_other_chapel,
    ADD COLUMN IF NOT EXISTS transfer_circuit VARCHAR(150) NULL AFTER transfer_diocese,
    ADD COLUMN IF NOT EXISTS transfer_society VARCHAR(150) NULL AFTER transfer_circuit,
    ADD COLUMN IF NOT EXISTS removal_note_provided TINYINT(1) NOT NULL DEFAULT 0 AFTER transfer_society,
    ADD COLUMN IF NOT EXISTS superintendent_name VARCHAR(150) NULL AFTER removal_note_provided;

ALTER TABLE members
    ADD INDEX idx_members_spouse_crn (spouse_crn),
    ADD INDEX idx_members_transfer_from_other_chapel (transfer_from_other_chapel);
