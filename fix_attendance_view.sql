-- Fix attendance_with_zkteco view creation for cPanel
-- This will drop the existing view if it exists and create a new one

-- Drop the view if it already exists
DROP VIEW IF EXISTS `attendance_with_zkteco`;

-- Create the view without DEFINER (cPanel compatible)
CREATE VIEW `attendance_with_zkteco` AS
SELECT 
    ar.id AS id,
    ar.session_id AS session_id,
    ar.member_id AS member_id,
    ar.status AS status,
    ar.marked_by AS marked_by,
    ar.created_at AS created_at,
    ar.sync_source AS sync_source,
    ar.verification_type AS verification_type,
    ar.device_timestamp AS device_timestamp,
    zrl.device_id AS device_id,
    zd.device_name AS device_name,
    zd.location AS device_location,
    m.first_name AS first_name,
    m.last_name AS last_name,
    m.crn AS crn,
    ats.title AS session_title,
    ats.service_date AS service_date
FROM attendance_records ar
LEFT JOIN zkteco_raw_logs zrl ON ar.zkteco_raw_log_id = zrl.id
LEFT JOIN zkteco_devices zd ON zrl.device_id = zd.id
LEFT JOIN members m ON ar.member_id = m.id
LEFT JOIN attendance_sessions ats ON ar.session_id = ats.id;
