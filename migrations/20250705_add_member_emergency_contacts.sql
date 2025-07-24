-- Migration: Create member_emergency_contacts table for scalable emergency contacts
CREATE TABLE IF NOT EXISTS member_emergency_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    mobile VARCHAR(30) NOT NULL,
    relationship VARCHAR(50) NOT NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- (Optional) You may want to migrate existing emergency contacts from members table to this new table.
