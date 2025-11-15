<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('view_member')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}
?>
// get_member_class_and_classes.php
require_once __DIR__.'/../config/config.php';
header('Content-Type: application/json');
$member_id = intval($_GET['member_id'] ?? 0);
if (!$member_id) {
    echo json_encode(['error'=>'No member_id']);
    exit;
}
// Get current class and church for member
$stmt = $conn->prepare('SELECT m.class_id, m.church_id, bc.name AS class_name FROM members m LEFT JOIN bible_classes bc ON m.class_id = bc.id WHERE m.id = ? LIMIT 1');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$res = $stmt->get_result();
if (!$row = $res->fetch_assoc()) {
    echo json_encode(['class_id'=>'','class_name'=>'','classes'=>[]]);
    exit;
}
$class_id = $row['class_id'];
$class_name = $row['class_name'];
$church_id = $row['church_id'];
// If no church_id, return empty classes
if (!$church_id) {
    echo json_encode(['class_id'=>$class_id,'class_name'=>$class_name ?: '', 'classes'=>[]]);
    exit;
}
// Get all classes in this church
$stmt2 = $conn->prepare('SELECT id, name FROM bible_classes WHERE church_id = ? ORDER BY name ASC');
$stmt2->bind_param('i', $church_id);
$stmt2->execute();
$res2 = $stmt2->get_result();
$classes = [];
while ($r = $res2->fetch_assoc()) {
    $classes[] = $r;
}
echo json_encode([
    'class_id' => $class_id,
    'class_name' => $class_name ?: '',
    'classes' => $classes
]);
