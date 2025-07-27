<?php
$__start = microtime(true);
// AJAX endpoint for single payment by logged-in member
if (session_status() === PHP_SESSION_NONE) session_start();
error_log('SESSION user_id: ' . print_r($_SESSION['user_id'], true));
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
if (!$is_super_admin && !has_permission('access_ajax_single_payment_member')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$member_id = intval($_SESSION['member_id']);
$payment_type_id = intval($_POST['payment_type_id'] ?? 0);
$amount = floatval($_POST['amount'] ?? 0);
$date = $_POST['payment_date'] ?? date('Y-m-d');
$description = trim($_POST['description'] ?? '');

// Duplicate check (ignore description for duplicate logic)
$dup_stmt = $conn->prepare('SELECT id FROM payments WHERE member_id=? AND payment_type_id=? AND amount=? AND payment_date=?');
$dup_stmt->bind_param('iids', $member_id, $payment_type_id, $amount, $date);
$dup_stmt->execute();
$dup_stmt->store_result();
if ($dup_stmt->num_rows > 0) {
    echo json_encode(['success' => false, 'error' => 'This payment has already been recorded.']);
    $dup_stmt->close();
    exit;
}
$dup_stmt->close();

if ($payment_type_id && $amount > 0) {
    if (!isset($_SESSION['user_id']) || !$_SESSION['user_id']) {
        error_log('ERROR: user_id not set in session for single payment insert!');
        echo json_encode(['success' => false, 'error' => 'User session error: Not logged in or session expired. Please log in again.']);
        exit;
    }
    $user_id = intval($_SESSION['user_id']);
    $stmt = $conn->prepare('INSERT INTO payments (member_id, payment_type_id, amount, payment_date, description, recorded_by) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iidssi', $member_id, $payment_type_id, $amount, $date, $description, $user_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Payment recorded successfully.']);
    } else {
        error_log('SINGLE PAYMENT INSERT ERROR: '. $stmt->error . ' | Values: member_id=' . $member_id . ', payment_type_id=' . $payment_type_id . ', amount=' . $amount . ', date=' . $date . ', desc=' . $description . ', user_id=' . $user_id);
        echo json_encode(['success' => false, 'error' => 'Error: Could not record payment. Please contact admin.']);
    }
    $stmt->close();
error_log('TIMING: Insert: ' . round((microtime(true) - $__after_dup)*1000, 2) . ' ms');
} else {
    echo json_encode(['success' => false, 'error' => 'Please select a payment type and enter a valid amount.']);
}
