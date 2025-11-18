<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Set header FIRST before any output
header('Content-Type: application/json');

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

// Get current user for audit trail
$assigned_by = $_SESSION['user_id'] ?? null;

// Start transaction
$conn->begin_transaction();

try {
    // 1. Deactivate any existing active leaders for this class
    $deactivate = $conn->prepare('UPDATE bible_class_leaders SET status = "inactive" WHERE class_id = ? AND status = "active"');
    $deactivate->bind_param('i', $class_id);
    $deactivate->execute();
    $deactivate->close();
    
    // 2. Insert new leader assignment into bible_class_leaders table
    $insert = $conn->prepare('INSERT INTO bible_class_leaders (class_id, user_id, assigned_by, status, notes) VALUES (?, ?, ?, "active", "Assigned via Bible Class List")');
    $insert->bind_param('iii', $class_id, $leader_user_id, $assigned_by);
    $insert->execute();
    $insert->close();
    
    // 3. Update bible_classes.leader_id for backward compatibility
    $update = $conn->prepare('UPDATE bible_classes SET leader_id = ? WHERE id = ?');
    $update->bind_param('ii', $leader_user_id, $class_id);
    $update->execute();
    $update->close();
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Class leader assigned successfully']);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
