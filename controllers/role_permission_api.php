<?php
// Start session immediately like other working API files
session_start();

// role_permission_api.php: Handles AJAX for getting and setting permissions for a role.
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';

header('Content-Type: application/json');

// Authentication check
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Please log in']);
    exit;
}

// Robust super admin bypass and permission check (consistent with other files)
$is_super_admin = is_super_admin();

if (!$is_super_admin && !has_permission('manage_roles')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Get all permissions and which are assigned to the role
    $role_id = isset($_GET['role_id']) ? intval($_GET['role_id']) : 0;
    if (!$role_id) {
        echo json_encode(['success' => false, 'error' => 'Missing role_id']);
        exit;
    }
    $perms = [];
    $all = $conn->query("SELECT id, name FROM permissions WHERE is_active = 1 ORDER BY name ASC");
    $assigned = [];
    $assignedStmt = $conn->prepare(
        'SELECT permission_id FROM role_permissions
          WHERE role_id = ? AND is_active = 1
            AND (expires_at IS NULL OR expires_at > NOW())'
    );
    $assignedStmt->bind_param('i', $role_id);
    $assignedStmt->execute();
    $res = $assignedStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $assigned[$row['permission_id']] = true;
    }
    while ($p = $all->fetch_assoc()) {
        $perms[] = [
            'id' => $p['id'],
            'name' => $p['name'],
            'assigned' => isset($assigned[$p['id']])
        ];
    }
    echo json_encode(['success' => true, 'permissions' => $perms]);
    exit;
}

if ($method === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        echo json_encode(['success' => false, 'error' => 'Your session token expired. Refresh the page and try again.']);
        exit;
    }
    // Assign permissions to a role
    $role_id = isset($_POST['role_id']) ? intval($_POST['role_id']) : 0;
    if (!$role_id) {
        echo json_encode(['success' => false, 'error' => 'Missing role_id']);
        exit;
    }
    $perms = isset($_POST['permissions']) && is_array($_POST['permissions'])
        ? array_values(array_unique(array_filter(array_map('intval', $_POST['permissions']), function ($id) {
            return $id > 0;
        })))
        : [];

    try {
        RBACServiceFactory::setConnection($conn);
        RBACServiceFactory::getRoleService()->syncPermissions(
            $role_id,
            $perms,
            (int) ($_SESSION['user_id'] ?? 0)
        );
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        error_log('ROLE PERMISSION SAVE ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to save role permissions']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unsupported method']);
http_response_code(405);
exit;
