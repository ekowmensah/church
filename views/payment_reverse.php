<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$uid = $_SESSION['user_id'] ?? 0;
$role_id = $_SESSION['role_id'] ?? 0;
$action = $_GET['action'] ?? '';
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    header('Location: payment_list.php?error=Invalid+payment+ID');
    exit;
}

// Fetch payment to check status
$stmt = $conn->prepare("SELECT * FROM payments WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
if (!$payment) {
    header('Location: payment_list.php?error=Payment+not+found');
    exit;
}

if ($action === 'undo') {
    // Only admin can undo
    if ($role_id != 1 && (!has_permission('approve_payment_reversal'))) {
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
    if ($role_id != 1 && (!has_permission('approve_payment_reversal'))) {
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
    if ($role_id != 1 && (!has_permission('approve_payment_reversal'))) {
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
if ($role_id != 1 && (!has_permission('reverse_payment'))) {
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
