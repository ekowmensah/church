<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../includes/sms.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_resend_token_sms')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

if (!isset($_POST['member_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing member_id']);
    exit;
}
$member_id = intval($_POST['member_id']);
$member = $conn->query("SELECT * FROM members WHERE id = $member_id")->fetch_assoc();
if (!$member) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Member not found']);
    exit;
}
if ($member['status'] !== 'pending') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Member is not pending']);
    exit;
}
if (empty($member['phone'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Member has no phone']);
    exit;
}

// Compose token link (reuse registration link logic)
$token = $member['token'] ?? '';
$link = BASE_URL . "/complete_registration.php?token=" . urlencode($token);

// Compose message (customize as needed)
$message = "Hello {$member['first_name']}, complete your registration here: $link";

$result = send_sms($member['phone'], $message);
if ((isset($result['code']) && ($result['code'] == 0 || $result['code'] === '2000')) || (isset($result['status']) && $result['status'] === 'success')) {
    echo json_encode(['success' => true, 'message' => 'SMS sent successfully']);
    exit;
} else {
    http_response_code(500);
    $error = isset($result['message']) ? $result['message'] : (json_encode($result));
    echo json_encode(['success' => false, 'error' => 'Failed to send SMS: ' . $error]);
    exit;
}
