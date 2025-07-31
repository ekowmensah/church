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
if (!$is_super_admin && !has_permission('access_ajax_get_member_by_srn')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$srn = trim($_GET['srn'] ?? '');
if ($srn === '') {
    echo json_encode(['success' => false, 'msg' => 'No SRN provided.']);
    exit;
}
$stmt = $conn->prepare('SELECT id, srn, first_name, last_name, middle_name, gender, YEAR(CURDATE())-YEAR(birth_date) AS age FROM members WHERE srn = ? LIMIT 1');
$stmt->bind_param('s', $srn);
$stmt->execute();
$result = $stmt->get_result();
if ($member = $result->fetch_assoc()) {
    echo json_encode(['success' => true, 'member' => $member]);
} else {
    echo json_encode(['success' => false, 'msg' => 'SRN not found.']);
}
