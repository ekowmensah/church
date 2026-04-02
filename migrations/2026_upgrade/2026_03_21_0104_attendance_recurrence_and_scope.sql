-- 2026_03_21_0104_attendance_recurrence_and_scope.sql
-- Purpose: support recurring/one-time attendance setup, scope ownership, and report metadata.

ALTER TABLE attendance_sessions
    MODIFY COLUMN recurrence_type ENUM('daily', 'weekly', 'monthly') DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS schedule_type ENUM('recurring', 'one_time') NOT NULL DEFAULT 'one_time' AFTER is_recurring,
    ADD COLUMN IF NOT EXISTS frequency ENUM('daily', 'weekly', 'monthly') DEFAULT NULL AFTER schedule_type,
    ADD COLUMN IF NOT EXISTS attendance_scope ENUM('bible_class', 'organization', 'church', 'event', 'other') NOT NULL DEFAULT 'church' AFTER frequency,
    ADD COLUMN IF NOT EXISTS scope_id INT NULL AFTER attendance_scope,
    ADD COLUMN IF NOT EXISTS meeting_days VARCHAR(100) NULL AFTER recurrence_day,
    ADD COLUMN IF NOT EXISTS average_attendance DECIMAL(8,2) NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS is_synced_to_class_book TINYINT(1) NOT NULL DEFAULT 0 AFTER average_attendance,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER is_synced_to_class_book;

UPDATE attendance_sessions
SET schedule_type = CASE WHEN is_recurring = 1 THEN 'recurring' ELSE 'one_time' END,
    frequency = CASE
        WHEN recurrence_type IN ('daily', 'weekly', 'monthly') THEN recurrence_type
        ELSE NULL
    END
WHERE schedule_type IS NOT NULL;

ALTER TABLE attendance_sessions
    ADD INDEX idx_attendance_sessions_scope_date (attendance_scope, scope_id, service_date),
    ADD INDEX idx_attendance_sessions_schedule (schedule_type, frequency);
