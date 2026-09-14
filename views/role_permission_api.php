<?php
// role_permission_api.php: Handles AJAX for getting and setting permissions for a role.
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

header('Content-Type: application/json');

if (!is_logged_in() || !has_permission('manage_roles')) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    http_response_code(403);
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
    $all = $conn->query("SELECT id, name FROM permissions ORDER BY name ASC");
    $assigned = [];
    $res = $conn->query("SELECT permission_id FROM role_permissions WHERE role_id = $role_id");
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

    $conn->begin_transaction();
    try {
        $delete = $conn->prepare('DELETE FROM role_permissions WHERE role_id = ?');
        $delete->bind_param('i', $role_id);
        $delete->execute();

        if (!empty($perms)) {
            $insert = $conn->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            foreach ($perms as $permission_id) {
                $insert->bind_param('ii', $role_id, $permission_id);
                $insert->execute();
            }
        }

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('ROLE PERMISSION SAVE ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to save role permissions']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unsupported method']);
http_response_code(405);
exit;
