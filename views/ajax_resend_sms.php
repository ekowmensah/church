<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../includes/sms.php';

function sms_resend_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sms_resend_response(405, ['success' => false, 'error' => 'Method not allowed.']);
}
if (!is_logged_in()) {
    sms_resend_response(401, ['success' => false, 'error' => 'Authentication required.']);
}
if (!is_super_admin() && !has_permission('resend_sms')) {
    sms_resend_response(403, ['success' => false, 'error' => 'You cannot resend SMS messages.']);
}
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
    sms_resend_response(419, ['success' => false, 'error' => 'Your session token expired. Refresh and try again.']);
}

$requiredAuditColumns = [
    'church_id', 'payment_id', 'sender', 'provider_message_id',
    'attempt_number', 'retry_of_sms_log_id', 'attempted_by_user_id', 'error_message',
];
$availableAuditColumns = sms_log_columns($conn);
foreach ($requiredAuditColumns as $requiredColumn) {
    if (!isset($availableAuditColumns[$requiredColumn])) {
        sms_resend_response(503, [
            'success' => false,
            'error' => 'Apply Phase 0056 before retrying failed SMS messages.',
        ]);
    }
}

$logId = filter_var($_POST['id'] ?? $_POST['log_id'] ?? null, FILTER_VALIDATE_INT);
if (!$logId || $logId < 1) {
    sms_resend_response(422, ['success' => false, 'error' => 'Select a valid SMS attempt.']);
}

$stmt = $conn->prepare('SELECT * FROM sms_logs WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $logId);
$stmt->execute();
$source = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$source) {
    sms_resend_response(404, ['success' => false, 'error' => 'SMS attempt not found.']);
}

$sourceStatus = strtolower(trim((string) ($source['status'] ?? '')));
if (strpos($sourceStatus, 'fail') === false && strpos($sourceStatus, 'error') === false) {
    sms_resend_response(409, [
        'success' => false,
        'error' => 'Only a failed SMS attempt can be retried.',
    ]);
}

$isSuperAdmin = is_super_admin();
$sessionChurchId = (int) ($_SESSION['church_id'] ?? 0);
if (!$isSuperAdmin && $sessionChurchId <= 0 && (int) ($_SESSION['user_id'] ?? 0) > 0) {
    $church = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
    $userId = (int) $_SESSION['user_id'];
    $church->bind_param('i', $userId);
    $church->execute();
    $sessionChurchId = (int) (($church->get_result()->fetch_assoc()['church_id'] ?? 0));
    $church->close();
}
$sourceChurchId = (int) ($source['church_id'] ?? 0);
if (!$isSuperAdmin && ($sourceChurchId <= 0 || $sourceChurchId !== $sessionChurchId)) {
    sms_resend_response(403, [
        'success' => false,
        'error' => 'This SMS attempt is outside your church scope.',
    ]);
}

$provider = strtolower((string) ($source['provider'] ?? ''));
if (!in_array($provider, ['arkesel', 'hubtel'], true)) {
    $provider = null;
}
$context = [
    'member_id' => (int) ($source['member_id'] ?? 0),
    'sundayschool_id' => (int) ($source['sundayschool_id'] ?? 0),
    'church_id' => $sourceChurchId,
];
$result = log_sms(
    (string) $source['phone'],
    (string) $source['message'],
    !empty($source['payment_id']) ? (int) $source['payment_id'] : null,
    (string) ($source['type'] ?? 'general'),
    !empty($source['sender']) ? (string) $source['sender'] : null,
    $context,
    $provider,
    (int) $source['id'],
    (int) ($_SESSION['user_id'] ?? 0)
);

$sent = strtolower((string) ($result['status'] ?? '')) === 'success';
sms_resend_response($sent ? 200 : 502, [
    'success' => $sent,
    'message' => $sent ? 'SMS resent successfully.' : 'The provider did not accept the retry.',
    'error' => $sent ? null : (string) ($result['message'] ?? 'SMS retry failed.'),
    'sms_log_id' => (int) ($result['sms_log_id'] ?? 0),
    'audit_status' => (string) ($result['log_status'] ?? 'unknown'),
]);
