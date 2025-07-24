-- Migration: Add sundayschool_id to health_records for dual CRN/SRN support
ALTER TABLE health_records
  ADD COLUMN sundayschool_id INT NULL AFTER member_id,
  ADD CONSTRAINT fk_health_sundayschool FOREIGN KEY (sundayschool_id) REFERENCES sunday_school(id) ON DELETE SET NULL ON UPDATE CASCADE;
-- Optional: You may want to create an index for faster lookups
CREATE INDEX idx_health_sundayschool_id ON health_records(sundayschool_id);

-- Enforce only one of member_id or sundayschool_id can be set (application logic)
-- No direct SQL constraint for mutual exclusion, must be enforced in PHP code.
