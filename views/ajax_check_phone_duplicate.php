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
if (!$is_super_admin && !has_permission('access_ajax_check_phone_duplicate')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Normalize phone to 0XXXXXXXXX for all checks
function normalize_phone($phone) {
    $phone = preg_replace('/\D/', '', $phone);
    if (strpos($phone, '233') === 0) $phone = '0' . substr($phone, 3);
    if (strpos($phone, '0') !== 0 && strlen($phone) === 9) $phone = '0' . $phone;
    return $phone;
}

$input = $_GET['phone'] ?? '';
$phone = normalize_phone($input);
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$phone || strlen($phone) < 10) {
    echo json_encode(['exists'=>false]);
    exit;
}

// Robust duplicate check: match last 9 digits in both tables using UNION
function phone_last9($phone) {
    $phone = preg_replace('/\D/', '', $phone);
    return substr($phone, -9);
}
$phone9 = phone_last9($phone);

$params = [$phone9];
$where_sunday = "RIGHT(REPLACE(REPLACE(REPLACE(contact, '+233', ''), ' ', ''), '-', ''), 9) = ?";
$where_member = "RIGHT(REPLACE(REPLACE(REPLACE(phone, '+233', ''), ' ', ''), '-', ''), 9) = ?";
if ($id) {
    $where_sunday .= " AND id != ?";
    $params[] = $id;
}

$sql = "SELECT id FROM sunday_school WHERE $where_sunday UNION ALL SELECT id FROM members WHERE $where_member LIMIT 1";
$stmt = $conn->prepare($sql);
if ($id) {
    $stmt->bind_param('sis', $phone9, $id, $phone9);
} else {
    $stmt->bind_param('ss', $phone9, $phone9);
}
$stmt->execute();
$stmt->store_result();
$exists = $stmt->num_rows > 0;
$stmt->close();

header('Content-Type: application/json');
echo json_encode(['exists' => $exists]);
