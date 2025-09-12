<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_payment_types')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Get search term from request
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// Build query with search functionality
if (!empty($search)) {
    $stmt = $conn->prepare("SELECT id, name FROM payment_types WHERE active=1 AND name LIKE ? ORDER BY name ASC");
    $search_param = '%' . $search . '%';
    $stmt->bind_param('s', $search_param);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query("SELECT id, name FROM payment_types WHERE active=1 ORDER BY name ASC");
}

$results = [];
while($row = $res->fetch_assoc()) {
    $results[] = ['id' => $row['id'], 'text' => $row['name']];
}

echo json_encode(['results' => $results]);
