<?php
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
