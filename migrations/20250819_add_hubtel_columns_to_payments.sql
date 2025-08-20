-- Migration: Add client_reference and status columns for Hubtel payment tracking
ALTER TABLE payments
  ADD COLUMN client_reference VARCHAR(100) DEFAULT NULL AFTER payment_date,
  ADD COLUMN status VARCHAR(50) DEFAULT NULL AFTER client_reference;
