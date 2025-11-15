<?php
session_start();
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
if (!has_permission('send_sms')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}
?>
// Export filtered SMS logs to CSV for admin
require_once '../includes/admin_auth.php';
require_once '../includes/db.php';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="sms_logs_export_'.date('Ymd_His').'.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Date/Time','Recipient','Message','Sender','Payment ID','Type','Status','Result']);
$where = [];
$params = [];
$types = '';
if (!empty($_GET['member_id'])) {
    $where[] = 'phone IN (SELECT phone FROM members WHERE id = ?)';
    $params[] = intval($_GET['member_id']);
    $types .= 'i';
}
if (!empty($_GET['payment_id'])) {
    $where[] = 'payment_id = ?';
    $params[] = intval($_GET['payment_id']);
    $types .= 'i';
}
$sql = 'SELECT * FROM sms_logs';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY sent_at DESC';
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    fputcsv($out, [
        $row['sent_at'],
        $row['phone'],
        $row['message'],
        $row['sender'],
        $row['payment_id'],
        $row['type'],
        $row['status'],
        $row['result']
    ]);
}
fclose($out);
exit;
