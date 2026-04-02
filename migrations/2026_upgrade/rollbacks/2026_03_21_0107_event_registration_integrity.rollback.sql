-- rollback: 2026_03_21_0107_event_registration_integrity.sql

DROP VIEW IF EXISTS v_event_registration_summary;

ALTER TABLE event_registrations
    DROP INDEX uq_event_registrations_event_member,
    DROP INDEX idx_event_registrations_event_status,
    DROP INDEX idx_event_registrations_member_status;

ALTER TABLE event_registrations
    DROP COLUMN registration_status,
    DROP COLUMN registration_source,
    DROP COLUMN registered_by,
    DROP COLUMN notes;
