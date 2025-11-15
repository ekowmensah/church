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
if (!$is_super_admin && !has_permission('access_ajax_find_member_by_crn')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$crn = trim($_GET['crn'] ?? '');
if (!$crn) {
    echo json_encode(['error'=>'No CRN provided']);
    exit;
}
$stmt = $conn->prepare('SELECT id, crn, CONCAT(last_name, " ", first_name, " ", middle_name) AS full_name FROM members WHERE crn = ? LIMIT 1');
$stmt->bind_param('s', $crn);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    echo json_encode($row);
} else {
    echo json_encode(['error'=>'Member not found']);
}
