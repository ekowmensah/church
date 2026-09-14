<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('delete_visitor')) {
    http_response_code(403);
    exit('Forbidden: You do not have permission to access this resource.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('Invalid or expired deletion request.');
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) {
    header('Location: visitor_list.php?error=notfound');
    exit;
}

$sql = "DELETE FROM visitors WHERE id = ? AND conversion_status = 'visitor'";
$types = 'i';
$params = [$id];
if (!$is_super_admin) {
    $scope = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
    $scope->bind_param('i', $_SESSION['user_id']);
    $scope->execute();
    $church_id = (int) ($scope->get_result()->fetch_assoc()['church_id'] ?? 0);
    $scope->close();
    $sql .= ' AND church_id = ?';
    $types .= 'i';
    $params[] = $church_id;
}
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$deleted = $stmt->affected_rows === 1;
$stmt->close();

if ($deleted) {
    header('Location: visitor_list.php?deleted=1');
} else {
    header('Location: visitor_list.php?error=registered_or_notfound');
}
exit;
