-- Add 'active' column to payment_types table
ALTER TABLE payment_types ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1;

-- Add foreign key constraint to payments table for payment_type_id
ALTER TABLE payments ADD CONSTRAINT fk_payment_type_id FOREIGN KEY (payment_type_id) REFERENCES payment_types(id) ON UPDATE CASCADE ON DELETE RESTRICT;
