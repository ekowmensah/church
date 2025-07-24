-- Add extra fields for enhanced visitor form
default charset=utf8mb4;
ALTER TABLE visitors
  ADD COLUMN gender VARCHAR(10) NULL AFTER purpose,
  ADD COLUMN home_town VARCHAR(100) NULL AFTER gender,
  ADD COLUMN region VARCHAR(50) NULL AFTER home_town,
  ADD COLUMN occupation VARCHAR(100) NULL AFTER region,
  ADD COLUMN marital_status VARCHAR(20) NULL AFTER occupation,
  ADD COLUMN want_member VARCHAR(5) NULL AFTER marital_status;
