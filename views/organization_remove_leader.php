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
if (!has_permission('view_organization_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}
?>
require_once __DIR__.'/../config/config.php';
header('Content-Type: application/json');

$org_id = isset($_POST['org_id']) ? intval($_POST['org_id']) : 0;
if (!$org_id) {
    echo json_encode(['success' => false, 'error' => 'Missing organization ID.']);
    exit;
}
$stmt = $conn->prepare('UPDATE organizations SET leader_id = NULL WHERE id = ?');
$stmt->bind_param('i', $org_id);
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error.']);
}
$stmt->close();
