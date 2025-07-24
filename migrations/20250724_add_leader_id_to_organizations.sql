-- Migration: Add leader_id to organizations
-- Step 1: Add the column first
ALTER TABLE organizations ADD COLUMN leader_id INT NULL;

-- Step 2: Add foreign key constraint with explicit name
ALTER TABLE organizations ADD CONSTRAINT fk_organizations_leader_id FOREIGN KEY (leader_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE;
