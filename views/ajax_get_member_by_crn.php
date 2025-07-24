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
if (!$is_super_admin && !has_permission('access_ajax_get_member_by_crn')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$crn = trim($_GET['crn'] ?? '');
$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
$debug = isset($_GET['debug']) ? 1 : 0;
if ($debug) {
    error_log('DEBUG: Incoming CRN: ' . $crn);
}
if (!$crn) {
    echo json_encode(['success'=>false, 'msg'=>'No CRN provided']);
    exit;
}
if ($church_id > 0) {
    $stmt = $conn->prepare("SELECT m.id, m.crn, m.first_name, m.last_name, m.gender, DATE_FORMAT(m.dob, '%Y-%m-%d') as dob, m.status, m.church_id, m.phone, bc.name as class_name FROM members m LEFT JOIN bible_classes bc ON m.class_id = bc.id WHERE m.crn = ? AND m.church_id = ? LIMIT 1");
} else {
    $stmt = $conn->prepare("SELECT m.id, m.crn, m.first_name, m.last_name, m.gender, DATE_FORMAT(m.dob, '%Y-%m-%d') as dob, m.status, m.church_id, m.phone, bc.name as class_name FROM members m LEFT JOIN bible_classes bc ON m.class_id = bc.id WHERE m.crn = ? LIMIT 1");
}
if (!$stmt) {
    if ($debug) echo json_encode(['success'=>false, 'msg'=>'SQL prepare error: ' . $conn->error]);
    else echo json_encode(['success'=>false, 'msg'=>'Database error']);
    exit;
}
if ($church_id > 0) {
    $stmt->bind_param('si', $crn, $church_id);
} else {
    $stmt->bind_param('s', $crn);
}
if (!$stmt->execute()) {
    if ($debug) echo json_encode(['success'=>false, 'msg'=>'SQL execute error: ' . $stmt->error]);
    else echo json_encode(['success'=>false, 'msg'=>'Database error']);
    exit;
}
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    if (strtolower($row['status']) === 'pending') {
        echo json_encode(['success'=>false, 'msg'=>'Member Not Active']);
        exit;
    }
    // Calculate age
    $dob = $row['dob'];
    $age = null;
    if ($dob) {
        $from = new DateTime($dob);
        $to = new DateTime('now');
        $age = $from->diff($to)->y;
    }
    $row['age'] = $age;
    echo json_encode(['success'=>true, 'member'=>$row]);
} else {
    echo json_encode(['success'=>false, 'msg'=>'CRN not found']);
}
