<?php
// AJAX endpoint to check for duplicate role names
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
header('Content-Type: application/json');

// Authentication check
if (!is_logged_in()) {
    echo json_encode(['exists' => false, 'error' => 'Unauthorized']);
    exit;
}
// Permission check
if (!has_permission('view_dashboard')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['exists' => false, 'error' => 'Invalid request']);
    exit;
}

$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if (!$name) {
    echo json_encode(['exists' => false, 'error' => 'Missing role name']);
    exit;
}

$stmt = $conn->prepare('SELECT id FROM roles WHERE LOWER(name) = LOWER(?)' . ($id ? ' AND id != ?' : ''));
if ($id) {
    $stmt->bind_param('si', $name, $id);
} else {
    $stmt->bind_param('s', $name);
}
$stmt->execute();
$stmt->store_result();
$exists = $stmt->num_rows > 0;
$stmt->close();

echo json_encode(['exists' => $exists]);
exit;
