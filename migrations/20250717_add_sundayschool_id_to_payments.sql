-- Migration: Add sundayschool_id to payments table for Sunday School payment support
ALTER TABLE payments ADD COLUMN sundayschool_id INT DEFAULT NULL AFTER member_id;
-- Optionally add a foreign key if desired:
-- ALTER TABLE payments ADD CONSTRAINT fk_payments_sundayschool FOREIGN KEY (sundayschool_id) REFERENCES sunday_school(id) ON DELETE SET NULL;
