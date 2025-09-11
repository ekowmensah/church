-- Add hubtel_transaction_id field to payment_intents table
-- This field will store the transaction ID returned by Hubtel during payment initiation
-- Required for checking transaction status via Hubtel API

ALTER TABLE payment_intents 
ADD COLUMN hubtel_transaction_id VARCHAR(64) NULL AFTER client_reference,
ADD INDEX idx_hubtel_transaction_id (hubtel_transaction_id);

-- Update existing records to set hubtel_transaction_id = client_reference for backward compatibility
-- This is a temporary measure until proper transaction IDs are captured during payment initiation
UPDATE payment_intents 
SET hubtel_transaction_id = client_reference 
WHERE hubtel_transaction_id IS NULL;
