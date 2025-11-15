<?php
require_once __DIR__ . '/../../config/config.php';

$result = $conn->query("DESCRIBE users");
echo "Users table structure:\n";
while ($row = $result->fetch_assoc()) {
    echo "  {$row['Field']} ({$row['Type']})\n";
}
