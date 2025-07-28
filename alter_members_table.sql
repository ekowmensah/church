-- Alter members table to add AUTO_INCREMENT to ID column
-- First, check if the ID column already has AUTO_INCREMENT
-- If not, modify it to add AUTO_INCREMENT

USE church_management;

-- Show current table structure
DESCRIBE members;

-- Fix duplicate ID=0 records by assigning proper sequential IDs
-- Step 1: Add a temporary column to track row order
ALTER TABLE members ADD COLUMN temp_row_num INT AUTO_INCREMENT PRIMARY KEY FIRST;

-- Step 2: Update the id column with proper sequential values
UPDATE members SET id = temp_row_num;

-- Step 3: Drop the temporary column
ALTER TABLE members DROP COLUMN temp_row_num;

-- Step 4: Make id the primary key and set AUTO_INCREMENT
ALTER TABLE members ADD PRIMARY KEY (id);
ALTER TABLE members MODIFY COLUMN id INT AUTO_INCREMENT;

-- If you need to set a specific starting value (optional)
-- ALTER TABLE members AUTO_INCREMENT = 1;

-- Verify the change
DESCRIBE members;
