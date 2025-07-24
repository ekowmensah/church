<?php
// Permission Templates & Inheritance Logic
// Usage: assign templates to roles/users for bulk permission management

function get_permission_templates($conn) {
    $stmt = $conn->query("SELECT * FROM permission_templates");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_template_permissions($conn, $template_id) {
    $stmt = $conn->prepare("SELECT p.name FROM template_permissions tp JOIN permissions p ON tp.permission_id = p.id WHERE tp.template_id = ?");
    $stmt->execute([$template_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function assign_template_to_role($conn, $role_id, $template_id) {
    $permissions = get_template_permissions($conn, $template_id);
    foreach ($permissions as $perm) {
        $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, (SELECT id FROM permissions WHERE name = ?))");
        $stmt->execute([$role_id, $perm]);
    }
}

function assign_template_to_user($conn, $user_id, $template_id) {
    $permissions = get_template_permissions($conn, $template_id);
    foreach ($permissions as $perm) {
        $stmt = $conn->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_id, allowed) VALUES (?, (SELECT id FROM permissions WHERE name = ?), 1)");
        $stmt->execute([$user_id, $perm]);
    }
}

// In has_permission(), you can add logic to check templates assigned to the user's roles or directly to the user.
