<?php
$conn = require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

echo "Simple HikVision User Check...\n\n";

// Direct query to check data
$result = $conn->query("SELECT * FROM member_hikvision_data");

if ($result) {
    echo "Query executed successfully.\n";
    echo "Number of rows: " . $result->num_rows . "\n\n";
    
    if ($result->num_rows > 0) {
        echo "Data found:\n";
        while ($row = $result->fetch_assoc()) {
            echo "ID: " . $row['id'] . " | Device ID: " . $row['device_id'] . " | HikVision ID: " . $row['hikvision_user_id'] . " | Member ID: " . ($row['member_id'] ?? 'NULL') . " | Created: " . $row['created_at'] . "\n";
        }
    } else {
        echo "No data found in member_hikvision_data table.\n";
    }
} else {
    echo "Query failed: " . $conn->error . "\n";
}

$conn->close();
?>
