-- rollback: 2026_03_21_0104_attendance_recurrence_and_scope.sql

ALTER TABLE attendance_sessions
    DROP INDEX idx_attendance_sessions_scope_date,
    DROP INDEX idx_attendance_sessions_schedule;

ALTER TABLE attendance_sessions
    DROP COLUMN schedule_type,
    DROP COLUMN frequency,
    DROP COLUMN attendance_scope,
    DROP COLUMN scope_id,
    DROP COLUMN meeting_days,
    DROP COLUMN average_attendance,
    DROP COLUMN is_synced_to_class_book,
    DROP COLUMN created_by,
    MODIFY COLUMN recurrence_type ENUM('weekly', 'monthly') DEFAULT NULL;
