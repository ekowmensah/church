<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';

// Set header FIRST before any output
header('Content-Type: application/json');

// Authentication check
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Permission check
if (!is_super_admin() && !has_permission('edit_bibleclass')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Your session token expired. Refresh the page and try again.']);
    exit;
}

$class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
$leader_role = ($_POST['leader_role'] ?? 'primary') === 'assistant' ? 'assistant' : 'primary';
if (!$class_id) {
    echo json_encode(['success' => false, 'error' => 'Missing class ID.']);
    exit;
}

// Start transaction
$conn->begin_transaction();

try {
    // 1. Deactivate leader in bible_class_leaders table
    $deactivate = $conn->prepare('UPDATE bible_class_leaders SET status = "inactive" WHERE class_id = ? AND leader_role = ? AND status = "active"');
    $deactivate->bind_param('is', $class_id, $leader_role);
    $deactivate->execute();
    $deactivate->close();
    
    // 2. Remove leader from bible_classes table for backward compatibility
    if ($leader_role === 'primary') {
        $update = $conn->prepare('UPDATE bible_classes SET leader_id = NULL WHERE id = ?');
        $update->bind_param('i', $class_id);
        $update->execute();
        $update->close();
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => ucfirst($leader_role) . ' class leader removed successfully']);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
