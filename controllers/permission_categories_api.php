<?php
header('Content-Type: application/json');
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Check if user is logged in
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check permission to manage roles/permissions
if (!has_permission('manage_roles') && !has_permission('manage_permissions')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

try {
    // Load permission categories
    $categories = require __DIR__.'/../helpers/permission_categories.php';
    
    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load permission categories: ' . $e->getMessage()
    ]);
}
?>
