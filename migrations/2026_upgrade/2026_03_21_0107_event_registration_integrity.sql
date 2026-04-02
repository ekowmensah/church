-- 2026_03_21_0107_event_registration_integrity.sql
-- Purpose: enforce one registration per member per event and support admin visibility states.

ALTER TABLE event_registrations
    ADD COLUMN IF NOT EXISTS registration_status ENUM('registered', 'cancelled', 'attended', 'no_show') NOT NULL DEFAULT 'registered' AFTER registered_at,
    ADD COLUMN IF NOT EXISTS registration_source ENUM('portal', 'admin', 'ussd', 'import') NOT NULL DEFAULT 'portal' AFTER registration_status,
    ADD COLUMN IF NOT EXISTS registered_by INT NULL AFTER registration_source,
    ADD COLUMN IF NOT EXISTS notes VARCHAR(255) NULL AFTER registered_by;

-- Remove duplicate registrations before unique key creation.
DELETE er1
FROM event_registrations er1
JOIN event_registrations er2
  ON er1.event_id = er2.event_id
 AND er1.member_id = er2.member_id
 AND er1.id > er2.id
WHERE er1.event_id IS NOT NULL
  AND er1.member_id IS NOT NULL;

ALTER TABLE event_registrations
    ADD UNIQUE KEY uq_event_registrations_event_member (event_id, member_id),
    ADD INDEX idx_event_registrations_event_status (event_id, registration_status),
    ADD INDEX idx_event_registrations_member_status (member_id, registration_status);

CREATE OR REPLACE VIEW v_event_registration_summary AS
SELECT
    e.id AS event_id,
    e.name AS event_name,
    e.event_date,
    COUNT(er.id) AS total_registrations,
    SUM(CASE WHEN er.registration_status = 'registered' THEN 1 ELSE 0 END) AS registered_count,
    SUM(CASE WHEN er.registration_status = 'attended' THEN 1 ELSE 0 END) AS attended_count,
    SUM(CASE WHEN er.registration_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
    SUM(CASE WHEN er.registration_status = 'no_show' THEN 1 ELSE 0 END) AS no_show_count
FROM events e
LEFT JOIN event_registrations er ON er.event_id = e.id
GROUP BY e.id, e.name, e.event_date;
