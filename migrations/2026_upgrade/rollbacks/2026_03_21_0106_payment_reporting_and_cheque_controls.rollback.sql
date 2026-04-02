-- rollback: 2026_03_21_0106_payment_reporting_and_cheque_controls.sql

DROP TRIGGER IF EXISTS trg_payments_set_reporting_period_before_insert;
DROP TRIGGER IF EXISTS trg_payments_set_reporting_period_before_update;

ALTER TABLE payments
    DROP INDEX idx_payments_reporting_period_label,
    DROP INDEX idx_payments_payment_period,
    DROP INDEX idx_payments_cheque_verification,
    DROP INDEX idx_payments_recorded_by_date;

ALTER TABLE payments
    DROP COLUMN reporting_period_label,
    DROP COLUMN bank_name,
    DROP COLUMN is_cheque_verified,
    DROP COLUMN cheque_verified_by,
    DROP COLUMN cheque_verified_at,
    DROP COLUMN cheque_verification_notes;

ALTER TABLE cashier_denomination_entries
    DROP COLUMN notes_total,
    DROP COLUMN coins_total;
