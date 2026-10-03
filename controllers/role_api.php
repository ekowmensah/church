<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/RoleController.php';

header('Content-Type: application/json; charset=utf-8');

function role_api_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (!is_logged_in()) {
    role_api_response(['success' => false, 'error' => 'Authentication required.'], 401);
}
if (!is_super_admin() && !has_permission('manage_roles')) {
    role_api_response(['success' => false, 'error' => 'You do not have permission to manage roles.'], 403);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$rawBody = file_get_contents('php://input');
$input = $_POST;
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $input = array_merge($input, $decoded);
    } else {
        $formInput = [];
        parse_str($rawBody, $formInput);
        $input = array_merge($input, $formInput);
    }
}

if ($method !== 'GET' && !csrf_is_valid($input['csrf_token'] ?? null)) {
    role_api_response(['success' => false, 'error' => 'Your session token expired. Refresh the page and try again.'], 419);
}

$controller = new RoleController($conn);

try {
    switch ($method) {
        case 'GET':
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            if ($id > 0) {
                $role = $controller->read($id);
                if (!$role) role_api_response(['success' => false, 'error' => 'Role not found.'], 404);
                role_api_response(['success' => true, 'role' => $role]);
            }
            role_api_response(['success' => true, 'roles' => $controller->list()]);

        case 'POST':
            $action = strtolower((string) ($input['action'] ?? 'create'));
            if ($action === 'delete') {
                $id = (int) ($input['id'] ?? 0);
                if ($id < 1) role_api_response(['success' => false, 'error' => 'A valid role ID is required.'], 400);
                if (!$controller->delete($id)) role_api_response(['success' => false, 'error' => 'Unable to delete the role.'], 409);
                role_api_response(['success' => true]);
            }
            if ($action === 'update') {
                $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
                if ($id < 1) role_api_response(['success' => false, 'error' => 'A valid role ID is required.'], 400);
                $updated = $controller->update($id, $input);
                if (!$updated || isset($updated['error'])) {
                    role_api_response(['success' => false, 'error' => $updated['error'] ?? 'Unable to update the role.'], 400);
                }
                role_api_response(['success' => true, 'role' => $updated]);
            }
            $created = $controller->create($input);
            if (!$created || isset($created['error'])) {
                role_api_response(['success' => false, 'error' => $created['error'] ?? 'Unable to create the role.'], 400);
            }
            role_api_response(['success' => true, 'role' => $created], 201);

        case 'PUT':
            $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
            if ($id < 1) role_api_response(['success' => false, 'error' => 'A valid role ID is required.'], 400);
            $updated = $controller->update($id, $input);
            if (!$updated || isset($updated['error'])) {
                role_api_response(['success' => false, 'error' => $updated['error'] ?? 'Unable to update the role.'], 400);
            }
            role_api_response(['success' => true, 'role' => $updated]);

        case 'DELETE':
            $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
            if ($id < 1) role_api_response(['success' => false, 'error' => 'A valid role ID is required.'], 400);
            if (!$controller->delete($id)) role_api_response(['success' => false, 'error' => 'Unable to delete the role.'], 409);
            role_api_response(['success' => true]);

        default:
            role_api_response(['success' => false, 'error' => 'Unsupported request method.'], 405);
    }
} catch (Throwable $e) {
    error_log('ROLE API ERROR: ' . $e->getMessage());
    role_api_response(['success' => false, 'error' => 'The role operation could not be completed.'], 500);
}
