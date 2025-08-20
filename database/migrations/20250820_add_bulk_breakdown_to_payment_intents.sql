-- Add bulk_breakdown column to payment_intents table for storing bulk payment breakdowns
ALTER TABLE payment_intents
ADD COLUMN bulk_breakdown TEXT NULL AFTER payment_period_description;
