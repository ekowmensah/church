-- Migration: Drop old emergency contact fields from members table
ALTER TABLE members
    DROP COLUMN emergency_contact1_name,
    DROP COLUMN emergency_contact1_mobile,
    DROP COLUMN emergency_contact1_relationship,
    DROP COLUMN emergency_contact2_name,
    DROP COLUMN emergency_contact2_mobile,
    DROP COLUMN emergency_contact2_relationship;
