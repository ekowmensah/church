<?php
// Check if session is not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Permission check
if (!has_permission('view_health_list')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// Usage: include this at the top of health_form.php to prefill by member_id or crn
$prefill_crn = '';
$prefill_member_id = 0;
if (isset($_GET['member_id']) && intval($_GET['member_id'])) {
    $prefill_member_id = intval($_GET['member_id']);
    $stmt = $conn->prepare("SELECT crn FROM members WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $prefill_member_id);
    $stmt->execute();
    $stmt->bind_result($prefill_crn);
    $stmt->fetch();
    $stmt->close();
}
if (isset($_GET['crn']) && $_GET['crn']) {
    $prefill_crn = $_GET['crn'];
}
