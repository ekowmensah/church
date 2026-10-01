<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Authentication required.');
}
$isSuperAdmin = (int) ($_SESSION['role_id'] ?? 0) === 1;
if (!$isSuperAdmin && !has_permission('export_sms_logs')) {
    http_response_code(403);
    exit('You do not have permission to export SMS logs.');
}

$requiredAuditColumns = [
    'sundayschool_id', 'church_id', 'payment_id', 'sender',
    'provider_message_id', 'attempt_number', 'retry_of_sms_log_id',
    'attempted_by_user_id', 'error_message',
];
$availableAuditColumns = [];
$schemaResult = $conn->query('SHOW COLUMNS FROM sms_logs');
while ($schemaRow = $schemaResult->fetch_assoc()) {
    $availableAuditColumns[$schemaRow['Field']] = true;
}
foreach ($requiredAuditColumns as $requiredAuditColumn) {
    if (!isset($availableAuditColumns[$requiredAuditColumn])) {
        http_response_code(409);
        exit('Apply database Phase 0056 before exporting SMS delivery evidence.');
    }
}

$where = [];
$params = [];
$types = '';
if (!$isSuperAdmin) {
    $churchId = (int) ($_SESSION['church_id'] ?? 0);
    if ($churchId <= 0 && !empty($_SESSION['user_id'])) {
        $scope = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
        $userId = (int) $_SESSION['user_id'];
        $scope->bind_param('i', $userId);
        $scope->execute();
        $churchId = (int) (($scope->get_result()->fetch_assoc()['church_id'] ?? 0));
        $scope->close();
    }
    $where[] = 'log.church_id = ?';
    $params[] = $churchId;
    $types .= 'i';
}
if (!empty($_GET['member_id'])) {
    $where[] = 'log.member_id = ?';
    $params[] = (int) $_GET['member_id'];
    $types .= 'i';
}
if (!empty($_GET['payment_id'])) {
    $where[] = 'log.payment_id = ?';
    $params[] = (int) $_GET['payment_id'];
    $types .= 'i';
}
if (!empty($_GET['phone'])) {
    $where[] = 'log.phone LIKE ?';
    $params[] = '%' . trim((string) $_GET['phone']) . '%';
    $types .= 's';
}
if (!empty($_GET['sender'])) {
    $where[] = 'log.sender LIKE ?';
    $params[] = '%' . trim((string) $_GET['sender']) . '%';
    $types .= 's';
}
if (!empty($_GET['type'])) {
    $where[] = 'log.type = ?';
    $params[] = trim((string) $_GET['type']);
    $types .= 's';
}
if (!empty($_GET['status'])) {
    if ($_GET['status'] === 'sent') {
        $where[] = "LOWER(log.status) IN ('sent','success')";
    } elseif ($_GET['status'] === 'failed') {
        $where[] = "(LOWER(log.status) LIKE '%fail%' OR LOWER(log.status) LIKE '%error%')";
    }
}
if (!empty($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])) {
    $where[] = 'DATE(log.sent_at) >= ?';
    $params[] = $_GET['date_from'];
    $types .= 's';
}
if (!empty($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])) {
    $where[] = 'DATE(log.sent_at) <= ?';
    $params[] = $_GET['date_to'];
    $types .= 's';
}

$sql = 'SELECT log.* FROM sms_logs log';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY log.sent_at DESC, log.id DESC';
$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="sms_delivery_audit_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, [
    'Log ID', 'Date/Time', 'Church ID', 'Member ID', 'Sunday School ID',
    'Payment ID', 'Recipient', 'Message', 'Sender', 'Provider', 'Provider Message ID',
    'Type', 'Attempt', 'Retry Of', 'Status', 'Failure'
]);
while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        $row['id'], $row['sent_at'], $row['church_id'], $row['member_id'],
        $row['sundayschool_id'], $row['payment_id'], $row['phone'], $row['message'],
        $row['sender'], $row['provider'], $row['provider_message_id'], $row['type'],
        $row['attempt_number'], $row['retry_of_sms_log_id'], $row['status'],
        $row['error_message'],
    ]);
}
fclose($out);
$stmt->close();
exit;
