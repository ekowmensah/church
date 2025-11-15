<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_get_organizations_by_church')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$church_id = intval($_GET['church_id'] ?? 0);
if (!$church_id) exit;
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$sql = "SELECT id, name FROM organizations WHERE church_id = $church_id";
if ($search !== '') {
    $search_esc = $conn->real_escape_string($search);
    $sql .= " AND name LIKE '%$search_esc%'";
}
$sql .= " ORDER BY name ASC";
$res = $conn->query($sql);
$results = [];
while($row = $res->fetch_assoc()) {
    $results[] = [
        'id' => $row['id'],
        'text' => $row['name']
    ];
}
echo json_encode(['results' => $results]);
