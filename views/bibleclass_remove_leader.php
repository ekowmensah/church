<?php
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
