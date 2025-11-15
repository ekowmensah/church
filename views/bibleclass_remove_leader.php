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
if (!has_permission('view_bibleclass_list')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}
?>
require_once __DIR__.'/../config/config.php';
header('Content-Type: application/json');

$class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
if (!$class_id) {
    echo json_encode(['success' => false, 'error' => 'Missing class ID.']);
    exit;
}
$stmt = $conn->prepare('UPDATE bible_classes SET leader_id = NULL WHERE id = ?');
$stmt->bind_param('i', $class_id);
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error.']);
}
$stmt->close();
