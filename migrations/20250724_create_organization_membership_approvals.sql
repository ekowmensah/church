-- Migration: Create organization membership approval system
-- This table stores pending organization membership requests that require approval from Organization Leaders

CREATE TABLE organization_membership_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    organization_id INT NOT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by INT NULL, -- user_id of the Organization Leader who approved/rejected
    approved_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign key constraints
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    
    -- Prevent duplicate pending requests
    UNIQUE KEY unique_pending_request (member_id, organization_id, status),
    
    -- Indexes for performance
    INDEX idx_member_id (member_id),
    INDEX idx_organization_id (organization_id),
    INDEX idx_status (status),
    INDEX idx_requested_at (requested_at)
);

-- Add a comment to the table
ALTER TABLE organization_membership_approvals COMMENT = 'Stores pending organization membership requests that require approval from Organization Leaders';
