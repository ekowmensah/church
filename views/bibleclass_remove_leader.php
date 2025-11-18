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
if (!$class_id) {
    echo json_encode(['success' => false, 'error' => 'Missing class ID.']);
    exit;
}

// Start transaction
$conn->begin_transaction();

try {
    // 1. Deactivate leader in bible_class_leaders table
    $deactivate = $conn->prepare('UPDATE bible_class_leaders SET status = "inactive" WHERE class_id = ? AND status = "active"');
    $deactivate->bind_param('i', $class_id);
    $deactivate->execute();
    $deactivate->close();
    
    // 2. Remove leader from bible_classes table for backward compatibility
    $update = $conn->prepare('UPDATE bible_classes SET leader_id = NULL WHERE id = ?');
    $update->bind_param('i', $class_id);
    $update->execute();
    $update->close();
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Class leader removed successfully']);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
