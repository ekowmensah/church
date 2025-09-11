-- Create table for unmatched shortcode payments
-- These are payments that couldn't be automatically assigned to members

CREATE TABLE IF NOT EXISTS unmatched_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(15) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference VARCHAR(100) NOT NULL,
    description TEXT,
    transaction_date DATETIME NOT NULL,
    raw_data JSON,
    status ENUM('Pending', 'Completed', 'Failed') DEFAULT 'Completed',
    assigned_member_id INT NULL,
    assigned_by INT NULL,
    assigned_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_phone (phone),
    INDEX idx_reference (reference),
    INDEX idx_status (status),
    INDEX idx_assigned_member (assigned_member_id),
    
    FOREIGN KEY (assigned_member_id) REFERENCES members(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Add comment
ALTER TABLE unmatched_payments COMMENT = 'Stores shortcode payments that could not be automatically matched to members';
