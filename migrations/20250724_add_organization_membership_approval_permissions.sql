-- Migration: Add permissions for organization membership approval system
-- This adds the necessary permissions for viewing, approving, and rejecting organization membership requests

-- Insert permissions for organization membership approvals
INSERT INTO permissions (name, description) VALUES
('view_organization_membership_approvals', 'View pending organization membership approval requests'),
('approve_organization_memberships', 'Approve organization membership requests from members'),
('reject_organization_memberships', 'Reject organization membership requests from members')
ON DUPLICATE KEY UPDATE 
    description = VALUES(description);

-- Grant these permissions to Organization Leader role (role_id = 6)
-- First, get the permission IDs
SET @view_perm_id = (SELECT id FROM permissions WHERE name = 'view_organization_membership_approvals');
SET @approve_perm_id = (SELECT id FROM permissions WHERE name = 'approve_organization_memberships');
SET @reject_perm_id = (SELECT id FROM permissions WHERE name = 'reject_organization_memberships');

-- Grant permissions to Organization Leader role (role_id = 6)
INSERT INTO role_permissions (role_id, permission_id) VALUES
(6, @view_perm_id),
(6, @approve_perm_id),
(6, @reject_perm_id)
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Optional: Grant to Super Admin role (role_id = 1) if they don't already have these permissions
INSERT INTO role_permissions (role_id, permission_id) VALUES
(1, @view_perm_id),
(1, @approve_perm_id),
(1, @reject_perm_id)
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Add comment for documentation
ALTER TABLE organization_membership_approvals COMMENT = 'Stores pending organization membership requests that require approval from Organization Leaders with proper permission checks';
