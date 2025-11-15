<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

header('Content-Type: application/json');

// Authentication check
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
$result = $conn->query("SELECT id, name FROM payment_types WHERE active = 1 ORDER BY name ASC");
$types = [];
while ($row = $result->fetch_assoc()) {
    $types[] = $row;
}
echo json_encode($types);
