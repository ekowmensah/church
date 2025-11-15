<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_validate_user')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// AJAX endpoint to check if email or phone is already used in users table
$type = $_GET['type'] ?? '';
$value = trim($_GET['value'] ?? '');
$id = isset($_GET['id']) ? intval($_GET['id']) : 0; // For edit mode, exclude self

if (!$type || !$value || !in_array($type, ['email','phone'])) {
    echo json_encode(['valid' => false, 'msg' => 'Invalid request']);
    exit;
}

$sql = "SELECT id FROM users WHERE $type = ?";
$params = [$value];
$types = 's';
if ($id > 0) {
    $sql .= " AND id != ?";
    $params[] = $id;
    $types .= 'i';
}
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows > 0) {
    echo json_encode(['valid' => false, 'msg' => ucfirst($type).' already exists in users.']);
} else {
    echo json_encode(['valid' => true]);
}
