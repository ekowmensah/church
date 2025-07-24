-- Migration: Add roles_of_serving relation for members
CREATE TABLE IF NOT EXISTS member_roles_of_serving (
    member_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (member_id, role_id),
    CONSTRAINT fk_member_roles_of_serving_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_roles_of_serving_role FOREIGN KEY (role_id) REFERENCES roles_of_serving(id) ON DELETE CASCADE
);
