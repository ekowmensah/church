<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}
if (!is_super_admin() && !has_permission('manage_menu_items')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

// Retained only so old bookmarks fail safely. Production writes use menu_api.php.
http_response_code(410);
echo json_encode([
    'success' => false,
    'message' => 'This diagnostic endpoint has been retired. Use Menu Management.'
]);
