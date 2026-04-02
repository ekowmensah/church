-- 2026_03_21_0900_indexes_fk_hardening.sql
-- Purpose: add missing primary/unique keys and critical query indexes.

-- attendance_records: stabilize REPLACE/UPSERT behavior and reporting speed.
DELETE ar1
FROM attendance_records ar1
JOIN attendance_records ar2
  ON ar1.session_id = ar2.session_id
 AND ar1.member_id = ar2.member_id
 AND ar1.id > ar2.id
WHERE ar1.session_id IS NOT NULL
  AND ar1.member_id IS NOT NULL;

ALTER TABLE attendance_records
    ADD PRIMARY KEY (id),
    ADD UNIQUE KEY uq_attendance_records_session_member (session_id, member_id),
    ADD INDEX idx_attendance_records_member_status (member_id, status),
    ADD INDEX idx_attendance_records_session_status (session_id, status);

-- event_registrations: ensure key integrity in case earlier migrations were skipped.
DELETE er1
FROM event_registrations er1
JOIN event_registrations er2
  ON er1.event_id = er2.event_id
 AND er1.member_id = er2.member_id
 AND er1.id > er2.id
WHERE er1.event_id IS NOT NULL
  AND er1.member_id IS NOT NULL;

ALTER TABLE event_registrations
    ADD PRIMARY KEY (id),
    ADD UNIQUE KEY uq_event_registrations_event_member_hard (event_id, member_id),
    ADD INDEX idx_event_registrations_event_id (event_id),
    ADD INDEX idx_event_registrations_member_id (member_id);

-- cashier_denomination_entries: enforce one finalized row per cashier/day structure.
ALTER TABLE cashier_denomination_entries
    ADD PRIMARY KEY (id),
    ADD UNIQUE KEY uq_cashier_denomination_entries_cashier_date (cashier_id, entry_date),
    ADD INDEX idx_cashier_denomination_entries_status_date (status, entry_date);

-- member_emergency_contacts + member_transfers: add primary keys and access indexes.
ALTER TABLE member_emergency_contacts
    ADD PRIMARY KEY (id),
    ADD INDEX idx_member_emergency_contacts_member_id (member_id);

ALTER TABLE member_transfers
    ADD PRIMARY KEY (id),
    ADD INDEX idx_member_transfers_member_id (member_id),
    ADD INDEX idx_member_transfers_transfer_date (transfer_date);

-- member_roles_of_serving: deduplicate and enforce unique assignments.
CREATE TABLE IF NOT EXISTS _tmp_member_roles_of_serving AS
SELECT DISTINCT member_id, role_id
FROM member_roles_of_serving;

TRUNCATE TABLE member_roles_of_serving;

INSERT INTO member_roles_of_serving (member_id, role_id)
SELECT member_id, role_id
FROM _tmp_member_roles_of_serving;

DROP TABLE _tmp_member_roles_of_serving;

ALTER TABLE member_roles_of_serving
    ADD PRIMARY KEY (member_id, role_id),
    ADD INDEX idx_member_roles_of_serving_role_id (role_id);

-- payment/reporting path indexes
ALTER TABLE payments
    ADD INDEX idx_payments_member_period_type (member_id, payment_period, payment_type_id),
    ADD INDEX idx_payments_date_mode (payment_date, mode),
    ADD INDEX idx_payments_reversal_state (reversal_approved_at, reversal_undone_at);
