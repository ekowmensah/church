<?php
// AJAX/API endpoint for advanced permission management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/PermissionController.php';

// Start session for audit logging
//session_start();

header('Content-Type: application/json');

$controller = new PermissionController($conn);
$method = $_SERVER['REQUEST_METHOD'];

function json_response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

switch ($method) {
    case 'GET':
        // List all permissions, or get one if id is set
        if (isset($_GET['id'])) {
            $perm = $controller->read($_GET['id']);
            if ($perm) {
                json_response(['success' => true, 'permission' => $perm]);
            } else {
                json_response(['success' => false, 'error' => 'Permission not found'], 404);
            }
        } else {
            $perms = $controller->list();
            json_response(['success' => true, 'permissions' => $perms]);
        }
        break;
    case 'POST':
        // Create new permission
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $created = $controller->create($input);
        if ($created) {
            json_response(['success' => true, 'permission' => $created], 201);
        } else {
            json_response(['success' => false, 'error' => 'Failed to create permission'], 400);
        }
        break;
    case 'PUT':
        // Update permission
        parse_str(file_get_contents('php://input'), $put_vars);
        $input = json_decode(file_get_contents('php://input'), true) ?? $put_vars;
        $id = $input['id'] ?? $_GET['id'] ?? null;
        if (!$id) json_response(['success' => false, 'error' => 'Missing permission id'], 400);
        $updated = $controller->update($id, $input);
        if ($updated) {
            json_response(['success' => true, 'permission' => $updated]);
        } else {
            json_response(['success' => false, 'error' => 'Failed to update permission'], 400);
        }
        break;
    case 'DELETE':
        // Delete permission
        parse_str(file_get_contents('php://input'), $del_vars);
        $id = $del_vars['id'] ?? $_GET['id'] ?? null;
        if (!$id) json_response(['success' => false, 'error' => 'Missing permission id'], 400);
        $deleted = $controller->delete($id);
        if ($deleted) {
            json_response(['success' => true]);
        } else {
            json_response(['success' => false, 'error' => 'Failed to delete permission'], 400);
        }
        break;
    default:
        json_response(['success' => false, 'error' => 'Unsupported method'], 405);
}
