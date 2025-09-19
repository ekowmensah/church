<?php
require_once __DIR__.'/../config/config.php';
header('Content-Type: application/json');
$result = $conn->query("SELECT id, name FROM payment_types WHERE active = 1 ORDER BY name ASC");
$types = [];
while ($row = $result->fetch_assoc()) {
    $types[] = $row;
}
echo json_encode($types);
