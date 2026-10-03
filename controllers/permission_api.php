<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/PermissionController.php';

header('Content-Type: application/json; charset=utf-8');

function permission_api_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (!is_logged_in()) {
    permission_api_response(['success' => false, 'error' => 'Authentication required.'], 401);
}

if (!is_super_admin() && !has_permission('manage_permissions')) {
    permission_api_response(['success' => false, 'error' => 'You do not have permission to manage permissions.'], 403);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$rawBody = file_get_contents('php://input');
$input = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $input = $decoded;
    } else {
        parse_str($rawBody, $input);
    }
}
if ($method === 'POST') {
    $input = array_merge($_POST, $input);
}

if ($method !== 'GET' && !csrf_is_valid($input['csrf_token'] ?? null)) {
    permission_api_response(['success' => false, 'error' => 'Your session token expired. Refresh the page and try again.'], 419);
}

$controller = new PermissionController($conn);

try {
    switch ($method) {
        case 'GET':
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            if ($id > 0) {
                $permission = $controller->read($id);
                if (!$permission) {
                    permission_api_response(['success' => false, 'error' => 'Permission not found.'], 404);
                }
                permission_api_response(['success' => true, 'permission' => $permission]);
            }
            permission_api_response(['success' => true, 'permissions' => $controller->list()]);

        case 'POST':
            $created = $controller->create($input);
            if (!$created) {
                permission_api_response(['success' => false, 'error' => 'Unable to create the permission.'], 400);
            }
            permission_api_response(['success' => true, 'permission' => $created], 201);

        case 'PUT':
            $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
            if ($id < 1) {
                permission_api_response(['success' => false, 'error' => 'A valid permission ID is required.'], 400);
            }
            $updated = $controller->update($id, $input);
            if (!$updated) {
                permission_api_response(['success' => false, 'error' => 'Unable to update the permission.'], 400);
            }
            permission_api_response(['success' => true, 'permission' => $updated]);

        case 'DELETE':
            $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
            if ($id < 1) {
                permission_api_response(['success' => false, 'error' => 'A valid permission ID is required.'], 400);
            }
            if (!$controller->delete($id)) {
                permission_api_response(['success' => false, 'error' => 'Unable to delete this permission. It may be a protected system permission or still assigned.'], 409);
            }
            permission_api_response(['success' => true]);

        default:
            permission_api_response(['success' => false, 'error' => 'Unsupported request method.'], 405);
    }
} catch (InvalidArgumentException $e) {
    permission_api_response(['success' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('PERMISSION API ERROR: ' . $e->getMessage());
    permission_api_response(['success' => false, 'error' => 'The permission operation could not be completed.'], 500);
}
