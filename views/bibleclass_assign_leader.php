<?php
require_once __DIR__.'/../config/config.php';
header('Content-Type: application/json');

$class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
$leader_user_id = isset($_POST['leader_user_id']) ? intval($_POST['leader_user_id']) : 0;

if (!$class_id || !$leader_user_id) {
    echo json_encode(['success' => false, 'error' => 'Missing data.']);
    exit;
}

// Validate user exists and has Class Leader role
$role_check = $conn->prepare('SELECT u.id FROM users u INNER JOIN user_roles ur ON u.id = ur.user_id WHERE u.id = ? AND ur.role_id = 5');
$role_check->bind_param('i', $leader_user_id);
$role_check->execute();
$role_check->store_result();
if ($role_check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Selected user is not a Class Leader.']);
    $role_check->close();
    exit;
}
$role_check->close();

// Update class with new leader (store user_id as leader_id)
$stmt = $conn->prepare('UPDATE bible_classes SET leader_id = ? WHERE id = ?');
$stmt->bind_param('ii', $leader_user_id, $class_id);
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error.']);
}
$stmt->close();
