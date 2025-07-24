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
if (!$is_super_admin && !has_permission('access_ajax_resend_registration_link')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid member ID.']);
    exit;
}

$stmt = $conn->prepare('SELECT first_name, phone, registration_token FROM members WHERE id = ? AND status = "pending" LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$member = $result->fetch_assoc();

if (!$member || empty($member['phone']) || empty($member['registration_token'])) {
    echo json_encode(['success' => false, 'message' => 'Member not found or missing phone/registration token.']);
    exit;
}

$first_name = $member['first_name'];
$phone = $member['phone'];
$registration_link = rtrim(BASE_URL, '/') . '/views/complete_registration.php?token=' . urlencode($member['registration_token']);

require_once __DIR__.'/../includes/sms.php';
$msg = "Hi $first_name, click on the link to complete your registration: $registration_link";
$smsResult = send_sms($phone, $msg);
log_sms($phone, $msg, null, 'registration', null, [
    'member_name' => $first_name,
    'link' => $registration_link,
    'phone' => $phone,
    'template' => 'registration_link (resend)'
]);

if (isset($smsResult['status']) && $smsResult['status'] === 'success') {
    echo json_encode(['success' => true, 'message' => 'Registration link sent via SMS.']);
} else {
    $err = $smsResult['message'] ?? 'Unknown error';
    echo json_encode(['success' => false, 'message' => 'Failed to send SMS: ' . $err]);
}
