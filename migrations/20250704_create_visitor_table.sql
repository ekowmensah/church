-- Migration: Create visitors table
CREATE TABLE IF NOT EXISTS visitors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(32),
    email VARCHAR(255),
    address VARCHAR(255),
    visit_date DATE NOT NULL,
    invited_by INT NULL,
    FOREIGN KEY (invited_by) REFERENCES members(id) ON DELETE SET NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
