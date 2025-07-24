<?php
// Script to print all permission names in the DB, sorted
require_once __DIR__ . '/../config/config.php';
$res = $conn->query("SELECT name FROM permissions ORDER BY name ASC");
echo "Permissions in DB:\n";
while ($row = $res->fetch_assoc()) {
    echo "- {$row['name']}\n";
}
