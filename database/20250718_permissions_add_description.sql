-- Add 'description' column to permissions table for detailed explanations
ALTER TABLE permissions ADD COLUMN description TEXT DEFAULT NULL AFTER `group`;
