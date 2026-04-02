-- rollback: 2026_03_21_0101_add_member_spouse_marriage_fields.sql

ALTER TABLE members
    DROP INDEX idx_members_spouse_crn,
    DROP INDEX idx_members_transfer_from_other_chapel;

ALTER TABLE members
    DROP COLUMN spouse_crn,
    DROP COLUMN spouse_name,
    DROP COLUMN marriage_type,
    DROP COLUMN occupation,
    DROP COLUMN transfer_from_other_chapel,
    DROP COLUMN transfer_diocese,
    DROP COLUMN transfer_circuit,
    DROP COLUMN transfer_society,
    DROP COLUMN removal_note_provided,
    DROP COLUMN superintendent_name;
