<?php
require_once __DIR__ . '/config/config.php';

$device_ip = '192.168.5.201';
$conn = get_db_connection();

// Check if device exists
$stmt = $conn->prepare('SELECT id, device_name FROM hikvision_devices WHERE ip_address = ?');
$stmt->bind_param('s', $device_ip);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $device = $result->fetch_assoc();
    echo "Device found: ID {$device['id']}, Name: {$device['device_name']}\n";
} else {
    echo "No device found with IP $device_ip\n";
    echo "Creating device record...\n";
    
    // Create device record
    $insert_stmt = $conn->prepare('INSERT INTO hikvision_devices (church_id, device_name, ip_address, port, username, password, device_model) VALUES (1, ?, ?, 80, ?, ?, ?)');
    $device_name = 'Main Entrance Terminal';
    $username = 'admin';
    $password = '223344AD';
    $model = 'DS-K1T320MFWX';
    
    $insert_stmt->bind_param('sssss', $device_name, $device_ip, $username, $password, $model);
    
    if ($insert_stmt->execute()) {
        $device_id = $conn->insert_id;
        echo "Device created successfully with ID: $device_id\n";
    } else {
        echo "ERROR: Failed to create device record\n";
    }
    $insert_stmt->close();
}

$stmt->close();
$conn->close();
?>
