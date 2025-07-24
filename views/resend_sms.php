<?php
// AJAX endpoint to resend an SMS by sms_logs.id
require_once '../includes/admin_auth.php';
require_once '../includes/db.php';
require_once '../includes/sms.php';

header('Content-Type: application/json');
if (!isset($_POST['log_id']) || !is_numeric($_POST['log_id'])) {
    echo json_encode(['success' => false, 'msg' => 'Invalid request.']);
    exit;
}
$log_id = intval($_POST['log_id']);
$stmt = $conn->prepare('SELECT * FROM sms_logs WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $log_id);
$stmt->execute();
$res = $stmt->get_result();
if (!($row = $res->fetch_assoc())) {
    echo json_encode(['success' => false, 'msg' => 'Log not found.']);
    exit;
}
// Resend SMS
$phone = $row['phone'];
$message = $row['message'];
$sender = $row['sender'] ?? null;
$payment_id = $row['payment_id'] ?? null;
$type = $row['type'] ?? 'general';
$result = send_sms($phone, $message, $sender);
$status = (stripos($result, 'success') !== false || stripos($result, 'sent') !== false) ? 'sent' : 'failed';
// Log new attempt
$stmt2 = $conn->prepare('INSERT INTO sms_logs (phone, message, sender, payment_id, type, status, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
$stmt2->bind_param('sssiss', $phone, $message, $sender, $payment_id, $type, $status);
$stmt2->execute();
// If registration or transfer, optionally trigger additional hooks here (future: e.g., update other tables)
echo json_encode([
    'success' => $status === 'sent',
    'msg' => $status === 'sent' ? 'SMS resent successfully.' : 'SMS resend failed.',
    'status' => $status,
    'type' => $type
]);
