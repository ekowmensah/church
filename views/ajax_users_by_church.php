<?php
if (session_status() === PHP_SESSION_NONE) session_start();
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
if (!$is_super_admin && !has_permission('access_ajax_users_by_church')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Returns users with the Class Leader role (role_id=5) for a given church
ini_set('display_errors', 1);
error_reporting(E_ALL);
function output_error($msg) {
    echo json_encode(['error' => $msg]);
    exit;
}
if (!isset($conn) || !$conn) {
    output_error('No DB connection');
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

// role_id for Class Leader
$class_leader_role_id = 5;

// Build base query
$sql = "SELECT u.id, u.name, u.email FROM users u
    INNER JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN members m ON u.member_id = m.id
    WHERE ur.role_id = ?";
$params = [$class_leader_role_id];
$types = 'i';

// Check if users table has church_id column
$has_church_id = false;
$col_res = $conn->query("SHOW COLUMNS FROM users LIKE 'church_id'");
if ($col_res && $col_res->num_rows > 0) {
    $has_church_id = true;
}
if ($church_id && $has_church_id) {
    $sql .= " AND u.church_id = ?";
    $params[] = $church_id;
    $types .= 'i';
}
if ($class_id) {
    $sql .= " AND m.class_id = ?";
    $params[] = $class_id;
    $types .= 'i';
}
if ($q !== '') {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $q_like = "%$q%";
    $params[] = $q_like;
    $params[] = $q_like;
    $types .= 'ss';
}
$sql .= " ORDER BY u.name ASC LIMIT 20";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while ($row = $res->fetch_assoc()) {
    $items[] = [
        'id' => $row['id'],
        'text' => $row['name'] . ' (' . $row['email'] . ')',
        'email' => $row['email']
    ];
}
if (empty($items)) {
    $no_results = [
        'id' => 0,
        'text' => 'No eligible users found for this class.',
        'email' => ''
    ];
    echo json_encode([
        'results' => [$no_results]
    ]);
} else {
    echo json_encode(['results' => $items]);
}
