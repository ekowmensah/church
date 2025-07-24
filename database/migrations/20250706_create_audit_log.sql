-- Audit log table for tracking role and permission changes
CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(32) NOT NULL,
    entity_type VARCHAR(32) NOT NULL, -- 'role' or 'permission'
    entity_id INT DEFAULT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(entity_type),
    INDEX(entity_id),
    INDEX(user_id)
);
