<?php
// Robust Permission Helper for MyFreeman
// Supports: user overrides, role permissions, audit logging, and future templates/inheritance

if (!function_exists('has_permission')) {
function has_permission($permission, $user_id = null, $context = []) {
    global $conn;
    if (!$conn || !($conn instanceof mysqli)) {
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $conn = $GLOBALS['conn'];
        } else {
            // Try to require config if not already loaded
            $config_path = dirname(__DIR__) . '/config/config.php';
            if (file_exists($config_path)) {
                require_once $config_path;
                if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
                    $conn = $GLOBALS['conn'];
                }
            }
        }
    }
    if (!$conn || !($conn instanceof mysqli)) {
        error_log('permissions.php: $conn is not set or not a valid mysqli instance');
        return false;
    }
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? null;
        if (!$user_id) return false;
    }

    // 1. Super admin override (optional, adjust as needed)
    if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin']) {
        return true;
    }

    // 2. User-level overrides (deny > allow)
    $stmt = $conn->prepare("SELECT allowed FROM user_permissions WHERE user_id = ? AND permission_id = (SELECT id FROM permissions WHERE name = ?)");
    $stmt->bind_param('is', $user_id, $permission);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : false;
    if ($row) {
        // Optionally audit
        if (!$row['allowed']) log_permission_denial($user_id, $permission, 'user_override');
        return (bool)$row['allowed'];
    }

    // 3. Role-based permissions
    $stmt = $conn->prepare("
        SELECT 1
        FROM role_permissions rp
        JOIN user_roles ur ON rp.role_id = ur.role_id
        JOIN permissions p ON rp.permission_id = p.id
        WHERE ur.user_id = ? AND p.name = ?
        LIMIT 1
    ");
    $stmt->bind_param('is', $user_id, $permission);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->fetch_assoc()) {
        return true;
    }

    // 4. (Future) Permission templates/inheritance logic here

    // 5. Log denied check
    log_permission_denial($user_id, $permission, 'denied');
    return false;
}
} // end if !function_exists('has_permission')

function log_permission_denial($user_id, $permission, $reason = '') {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO permission_audit_log (actor_user_id, action, target_type, target_id, permission_id, details) VALUES (?, 'deny', 'user', ?, (SELECT id FROM permissions WHERE name = ?), ?)");
    $stmt->execute([
        $user_id,
        $user_id,
        $permission,
        $reason
    ]);
}
