-- Add advanced permissions system tables (non-destructive, additive)

-- User-level permission overrides
CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    allowed BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (user_id, permission_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

-- Permission templates (sets of permissions that can be assigned to roles/users)
CREATE TABLE IF NOT EXISTS permission_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT
);

CREATE TABLE IF NOT EXISTS template_permissions (
    template_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (template_id, permission_id),
    FOREIGN KEY (template_id) REFERENCES permission_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

-- User permission requests (self-service)
CREATE TABLE IF NOT EXISTS user_permission_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    status ENUM('pending','approved','denied') DEFAULT 'pending',
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT,
    reviewed_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
);

-- Permission audit log
CREATE TABLE IF NOT EXISTS permission_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT,
    action ENUM('add','remove','grant','deny','request','approve','reject'),
    target_type ENUM('role','user','template','system'),
    target_id INT,
    permission_id INT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    details TEXT,
    FOREIGN KEY (actor_user_id) REFERENCES users(id),
    FOREIGN KEY (permission_id) REFERENCES permissions(id)
);
