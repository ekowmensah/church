<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

header('Content-Type: text/html; charset=utf-8');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo '<option value="">-- Login Required --</option>';
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('view_bible_class')) {
    http_response_code(403);
    echo '<option value="">-- Permission Denied --</option>';
    exit;
}

$church_id = intval($_GET['church_id'] ?? 0);
if (!$church_id) {
    echo '<option value="">-- Select Class --</option>';
    exit;
}

$class_id = intval($_GET['class_id'] ?? 0);

// Use prepared statement to prevent SQL injection
$stmt = $conn->prepare("SELECT id, name FROM bible_classes WHERE church_id = ? ORDER BY name ASC");
$stmt->bind_param('i', $church_id);
$stmt->execute();
$result = $stmt->get_result();

$options = '<option value="">-- Select Class --</option>';
while($row = $result->fetch_assoc()) {
    $selected = ($class_id && $row['id'] == $class_id) ? ' selected="selected"' : '';
    $options .= '<option value="'.htmlspecialchars($row['id']).'"'.$selected.'>'.htmlspecialchars($row['name']).'</option>';
}
$stmt->close();

echo $options;
