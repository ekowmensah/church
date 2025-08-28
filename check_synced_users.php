<?php
$conn = require_once __DIR__ . '/config/config.php';

header('Content-Type: text/plain');

echo "Checking synced HikVision users...\n\n";

$result = $conn->query("
    SELECT 
        h.id,
        h.device_id,
        h.hikvision_user_id,
        h.member_id,
        h.created_at,
        d.device_name
    FROM member_hikvision_data h
    LEFT JOIN hikvision_devices d ON h.device_id = d.id
    ORDER BY h.id DESC
");

if ($result->num_rows > 0) {
    echo "Found " . $result->num_rows . " synced users:\n";
    echo str_repeat("-", 80) . "\n";
    
    while ($row = $result->fetch_assoc()) {
        echo sprintf("ID: %-3s | Device: %-20s | HikVision ID: %-15s | Member ID: %-10s | Created: %s\n",
            $row['id'],
            $row['device_name'] ?? 'Unknown',
            $row['hikvision_user_id'],
            $row['member_id'] ?? 'Not Mapped',
            $row['created_at']
        );
    }
    
    echo str_repeat("-", 80) . "\n";
    
    $unmapped = $conn->query("SELECT COUNT(*) as count FROM member_hikvision_data WHERE member_id IS NULL")->fetch_assoc();
    echo "\nUnmapped users (need member mapping): " . $unmapped['count'] . "\n";
    
} else {
    echo "No synced users found.\n";
    echo "Try running the sync script: php sync_hikvision_users.php\n";
}

$conn->close();
?>
