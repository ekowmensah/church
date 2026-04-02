-- rollback: 2026_03_21_0900_indexes_fk_hardening.sql
-- Note: this rollback removes added secondary keys only. Primary keys are retained for safety.

ALTER TABLE attendance_records
    DROP INDEX uq_attendance_records_session_member,
    DROP INDEX idx_attendance_records_member_status,
    DROP INDEX idx_attendance_records_session_status;

ALTER TABLE event_registrations
    DROP INDEX uq_event_registrations_event_member_hard,
    DROP INDEX idx_event_registrations_event_id,
    DROP INDEX idx_event_registrations_member_id;

ALTER TABLE cashier_denomination_entries
    DROP INDEX uq_cashier_denomination_entries_cashier_date,
    DROP INDEX idx_cashier_denomination_entries_status_date;

ALTER TABLE member_emergency_contacts
    DROP INDEX idx_member_emergency_contacts_member_id;

ALTER TABLE member_transfers
    DROP INDEX idx_member_transfers_member_id,
    DROP INDEX idx_member_transfers_transfer_date;

ALTER TABLE member_roles_of_serving
    DROP INDEX idx_member_roles_of_serving_role_id;

ALTER TABLE payments
    DROP INDEX idx_payments_member_period_type,
    DROP INDEX idx_payments_date_mode,
    DROP INDEX idx_payments_reversal_state;
