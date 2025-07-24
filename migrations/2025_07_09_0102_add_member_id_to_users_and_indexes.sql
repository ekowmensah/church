-- Add index to member_organizations.organization_id for faster org-based queries
CREATE INDEX IF NOT EXISTS idx_member_organizations_organization_id ON member_organizations (organization_id);

-- Add index to member_organizations.member_id for faster member-based queries
CREATE INDEX IF NOT EXISTS idx_member_organizations_member_id ON member_organizations (member_id);

-- Optionally, add index to bible_classes.leader_id and organizations.leader_id if leader queries are frequent
CREATE INDEX IF NOT EXISTS idx_bible_classes_leader_id ON bible_classes (leader_id);
