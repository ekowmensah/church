<?php
require_once __DIR__ . '/../../config/config.php';
$result = $conn->query("DESCRIBE roles");
echo "Roles table columns:\n";
while ($row = $result->fetch_assoc()) {
    echo "  {$row['Field']}\n";
}
