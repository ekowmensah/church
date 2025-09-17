<?php
// Ensure session is started properly
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// AJAX/API endpoint for advanced role management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/RoleController.php';

// Set proper headers for AJAX requests
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Handle CORS if needed
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

// Debug session information for troubleshooting
$debug_info = [
    'session_id' => session_id(),
    'session_data' => $_SESSION ?? [],
    'user_id' => $_SESSION['user_id'] ?? 'not_set',
    'role_id' => $_SESSION['role_id'] ?? 'not_set',
    'member_id' => $_SESSION['member_id'] ?? 'not_set',
    'cookies' => $_COOKIE ?? [],
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not_set',
    'http_host' => $_SERVER['HTTP_HOST'] ?? 'not_set',
    'session_status' => session_status(),
    'session_name' => session_name()
];

// Authentication and robust super admin bypass
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions.php';
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'error' => 'Unauthorized - Please log in',
        'debug' => $debug_info
    ]);
    exit;
}
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 1) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('manage_roles')) {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'error' => 'Forbidden - Insufficient permissions',
        'debug' => array_merge($debug_info, [
            'is_super_admin' => $is_super_admin,
            'has_manage_roles_permission' => has_permission('manage_roles')
        ])
    ]);
    exit;
}

// Ensure database connection is available
global $conn;
if (!isset($conn)) {
    $conn = $GLOBALS['conn'];
}
$controller = new RoleController($conn);
$method = $_SERVER['REQUEST_METHOD'];

function json_response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Log the request for debugging
error_log("Role API Request - Method: $method, Input: " . json_encode($_POST) . " | JSON: " . file_get_contents('php://input'));

switch ($method) {
    case 'GET':
        // List all roles, or get one if id is set
        if (isset($_GET['id'])) {
            $role = $controller->read($_GET['id']);
            if ($role) {
                json_response(['success' => true, 'role' => $role]);
            } else {
                json_response(['success' => false, 'error' => 'Role not found'], 404);
            }
        } else {
            $roles = $controller->list();
            json_response(['success' => true, 'roles' => $roles]);
        }
        break;
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        // Handle delete via POST
        if (($input['action'] ?? '') === 'delete') {
            $id = $input['id'] ?? null;
            if (!$id) json_response(['success' => false, 'error' => 'Missing role id'], 400);
            $deleted = $controller->delete($id);
            if ($deleted) {
                json_response(['success' => true]);
            } else {
                json_response(['success' => false, 'error' => 'Failed to delete role'], 400);
            }
            break;
        }
        // Update role if action=update
        if (isset($_GET['action']) && $_GET['action'] === 'update' || isset($input['action']) && $input['action'] === 'update') {
            $id = $input['id'] ?? $_GET['id'] ?? null;
            if (!$id) json_response(['success' => false, 'error' => 'Missing role id'], 400);
            $updated = $controller->update($id, $input);
            if ($updated && !isset($updated['error'])) {
                json_response(['success' => true, 'role' => $updated]);
            } else {
                $errMsg = isset($updated['error']) ? $updated['error'] : 'Failed to update role';
                json_response(['success' => false, 'error' => $errMsg], 400);
            }
            break;
        }
        // Otherwise, create new role
        $created = $controller->create($input);
        if ($created && !isset($created['error'])) {
            json_response(['success' => true, 'role' => $created], 201);
        } else {
            $error_msg = isset($created['error']) ? $created['error'] : 'Failed to create role';
            error_log("Role creation failed: " . $error_msg . " | Input: " . json_encode($input));
            json_response(['success' => false, 'error' => $error_msg, 'debug' => $debug_info], 400);
        }
        break;
    case 'PUT':
        // Update role
        parse_str(file_get_contents('php://input'), $put_vars);
        $input = json_decode(file_get_contents('php://input'), true) ?? $put_vars;
        $id = $input['id'] ?? $_GET['id'] ?? null;
        if (!$id) json_response(['success' => false, 'error' => 'Missing role id'], 400);
        $updated = $controller->update($id, $input);
        if ($updated) {
            json_response(['success' => true, 'role' => $updated]);
        } else {
            json_response(['success' => false, 'error' => 'Failed to update role'], 400);
        }
        break;
    case 'DELETE':
        // Delete role
        parse_str(file_get_contents('php://input'), $del_vars);
        $id = $del_vars['id'] ?? $_GET['id'] ?? null;
        if (!$id) json_response(['success' => false, 'error' => 'Missing role id'], 400);
        $deleted = $controller->delete($id);
        if ($deleted) {
            json_response(['success' => true]);
        } else {
            json_response(['success' => false, 'error' => 'Failed to delete role'], 400);
        }
        break;
    default:
        json_response(['success' => false, 'error' => 'Unsupported method'], 405);
}
