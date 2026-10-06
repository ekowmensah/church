<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';
require_once __DIR__.'/../helpers/payment_report_context.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('Use the protected payment-reversal form.');
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? 'request'));
$id = (int) ($_POST['id'] ?? 0);

if (!$id) {
    header('Location: payment_list.php?error=Invalid+payment+ID');
    exit;
}

// Fetch payment to check status
$churchId = payment_report_current_church_id($conn);
$stmt = $conn->prepare(is_super_admin()
    ? 'SELECT * FROM payments WHERE id = ? LIMIT 1'
    : 'SELECT * FROM payments WHERE id = ? AND church_id = ? LIMIT 1');
if (is_super_admin()) {
    $stmt->bind_param('i', $id);
} else {
    $stmt->bind_param('ii', $id, $churchId);
}
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
if (!$payment) {
    header('Location: payment_list.php?error=Payment+not+found');
    exit;
}

if ($action === 'undo') {
    // Only admin can undo
    if (!is_super_admin() && !has_permission('approve_payment_reversal')) {
        die('No permission to undo reversal');
    }
    if (empty($payment['reversal_approved_at'])) {
        header('Location: payment_list.php?error=Not+reversed');
        exit;
    }
    // Undo reversal
    $stmt = $conn->prepare("UPDATE payments SET reversal_undone_at = NOW(), reversal_undone_by = ? WHERE id = ?");
    $stmt->bind_param('ii', $uid, $id);
    $stmt->execute();
    // Log
    $stmt = $conn->prepare("INSERT INTO payment_reversal_log (payment_id, action, actor_id, reason) VALUES (?, 'undo', ?, ?)");
    $reason = 'Undo reversal';
    $stmt->bind_param('iis', $id, $uid, $reason);
    $stmt->execute();
    header('Location: payment_list.php?undo=1');
    exit;
}

if ($action === 'approve') {
    // Only admin can approve
    if (!is_super_admin() && !has_permission('approve_payment_reversal')) {
        die('No permission to approve reversal');
    }
    if (empty($payment['reversal_requested_at']) || !empty($payment['reversal_approved_at'])) {
        header('Location: payment_list.php?error=Not+pending+approval');
        exit;
    }
    // Approve reversal
    $stmt = $conn->prepare("UPDATE payments SET reversal_approved_at = NOW(), reversal_approved_by = ? WHERE id = ?");
    $stmt->bind_param('ii', $uid, $id);
    $stmt->execute();
    // Log
    $stmt = $conn->prepare("INSERT INTO payment_reversal_log (payment_id, action, actor_id, reason) VALUES (?, 'approve', ?, ?)");
    $reason = 'Approved by admin';
    $stmt->bind_param('iis', $id, $uid, $reason);
    $stmt->execute();
    header('Location: payment_list.php?reversal_approved=1');
    exit;
}

if ($action === 'deny') {
    // Only admin can deny
    if (!is_super_admin() && !has_permission('approve_payment_reversal')) {
        die('No permission to deny reversal');
    }
    if (empty($payment['reversal_requested_at']) || !empty($payment['reversal_approved_at'])) {
        header('Location: payment_list.php?error=Not+pending+approval');
        exit;
    }
    // Clear the reversal request
    $stmt = $conn->prepare("UPDATE payments SET reversal_requested_at = NULL, reversal_requested_by = NULL WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    // Log
    $stmt = $conn->prepare("INSERT INTO payment_reversal_log (payment_id, action, actor_id, reason) VALUES (?, 'deny', ?, ?)");
    $reason = 'Denied by admin';
    $stmt->bind_param('iis', $id, $uid, $reason);
    $stmt->execute();
    header('Location: payment_reversal_log.php?reversal_denied=1');
    exit;
}

// Request reversal
if (!empty($payment['reversal_requested_at']) && empty($payment['reversal_approved_at'])) {
    header('Location: payment_list.php?error=Reversal+already+requested');
    exit;
}
if (!empty($payment['reversal_approved_at']) && empty($payment['reversal_undone_at'])) {
    header('Location: payment_list.php?error=Already+reversed');
    exit;
}
// Allow only permitted users
if (!is_super_admin() && !has_permission('reverse_payment')) {
    die('No permission to request reversal');
}
// Request reversal
$stmt = $conn->prepare("UPDATE payments SET reversal_requested_at = NOW(), reversal_requested_by = ? WHERE id = ?");
$stmt->bind_param('ii', $uid, $id);
$stmt->execute();
// Log
$stmt = $conn->prepare("INSERT INTO payment_reversal_log (payment_id, action, actor_id, reason) VALUES (?, 'request', ?, ?)");
$reason = 'Requested by user';
$stmt->bind_param('iis', $id, $uid, $reason);
$stmt->execute();
header('Location: payment_list.php?reversal_requested=1');
exit;
