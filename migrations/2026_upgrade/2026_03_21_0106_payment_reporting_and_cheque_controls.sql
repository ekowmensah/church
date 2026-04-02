-- 2026_03_21_0106_payment_reporting_and_cheque_controls.sql
-- Purpose: enforce reporting-period consistency and cheque verification fields.

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS reporting_period_label VARCHAR(100) NULL AFTER payment_period_description,
    ADD COLUMN IF NOT EXISTS bank_name VARCHAR(120) NULL AFTER cheque_number,
    ADD COLUMN IF NOT EXISTS is_cheque_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER bank_name,
    ADD COLUMN IF NOT EXISTS cheque_verified_by INT NULL AFTER is_cheque_verified,
    ADD COLUMN IF NOT EXISTS cheque_verified_at DATETIME NULL AFTER cheque_verified_by,
    ADD COLUMN IF NOT EXISTS cheque_verification_notes VARCHAR(255) NULL AFTER cheque_verified_at;

ALTER TABLE payments
    ADD INDEX idx_payments_reporting_period_label (reporting_period_label),
    ADD INDEX idx_payments_payment_period (payment_period),
    ADD INDEX idx_payments_cheque_verification (is_cheque_verified, cheque_verified_at),
    ADD INDEX idx_payments_recorded_by_date (recorded_by, payment_date);

UPDATE payments
SET reporting_period_label = CASE
    WHEN payment_period_description IS NOT NULL AND payment_period_description <> '' THEN payment_period_description
    WHEN payment_period IS NOT NULL THEN DATE_FORMAT(payment_period, '%M %Y')
    WHEN payment_date IS NOT NULL THEN DATE_FORMAT(payment_date, '%M %Y')
    ELSE NULL
END
WHERE reporting_period_label IS NULL OR reporting_period_label = '';

ALTER TABLE cashier_denomination_entries
    ADD COLUMN IF NOT EXISTS notes_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER denom_10_p,
    ADD COLUMN IF NOT EXISTS coins_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER notes_total;

UPDATE cashier_denomination_entries
SET notes_total = (
        (denom_200 * 200) + (denom_100 * 100) + (denom_50 * 50) + (denom_20 * 20) +
        (denom_10 * 10) + (denom_5 * 5) + (denom_2 * 2) + (denom_1 * 1)
    ),
    coins_total = (
        (denom_2_Coin * 2) + (denom_1_Coin * 1) + (denom_50_p * 0.50) +
        (denom_20_p * 0.20) + (denom_10_p * 0.10)
    );

DROP TRIGGER IF EXISTS trg_payments_set_reporting_period_before_insert;
DROP TRIGGER IF EXISTS trg_payments_set_reporting_period_before_update;

DELIMITER $$

CREATE TRIGGER trg_payments_set_reporting_period_before_insert
BEFORE INSERT ON payments
FOR EACH ROW
BEGIN
    IF NEW.reporting_period_label IS NULL OR NEW.reporting_period_label = '' THEN
        SET NEW.reporting_period_label = CASE
            WHEN NEW.payment_period_description IS NOT NULL AND NEW.payment_period_description <> '' THEN NEW.payment_period_description
            WHEN NEW.payment_period IS NOT NULL THEN DATE_FORMAT(NEW.payment_period, '%M %Y')
            WHEN NEW.payment_date IS NOT NULL THEN DATE_FORMAT(NEW.payment_date, '%M %Y')
            ELSE NULL
        END;
    END IF;
END$$

CREATE TRIGGER trg_payments_set_reporting_period_before_update
BEFORE UPDATE ON payments
FOR EACH ROW
BEGIN
    IF NEW.reporting_period_label IS NULL OR NEW.reporting_period_label = '' THEN
        SET NEW.reporting_period_label = CASE
            WHEN NEW.payment_period_description IS NOT NULL AND NEW.payment_period_description <> '' THEN NEW.payment_period_description
            WHEN NEW.payment_period IS NOT NULL THEN DATE_FORMAT(NEW.payment_period, '%M %Y')
            WHEN NEW.payment_date IS NOT NULL THEN DATE_FORMAT(NEW.payment_date, '%M %Y')
            ELSE NULL
        END;
    END IF;
END$$

DELIMITER ;
