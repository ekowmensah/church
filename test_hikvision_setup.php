<?php
require_once __DIR__ . '/config/config.php';

echo "Testing HikVision setup...\n";

try {
    $conn = get_db_connection();
    echo "✓ Database connection successful\n";
    
    // Check if hikvision_devices table exists
    $result = $conn->query("SHOW TABLES LIKE 'hikvision_devices'");
    if ($result->num_rows > 0) {
        echo "✓ hikvision_devices table exists\n";
        
        // Check for device record
        $device_ip = '192.168.5.201';
        $stmt = $conn->prepare('SELECT id, device_name FROM hikvision_devices WHERE ip_address = ?');
        $stmt->bind_param('s', $device_ip);
        $stmt->execute();
        $device_result = $stmt->get_result();
        
        if ($device_result->num_rows > 0) {
            $device = $device_result->fetch_assoc();
            echo "✓ Device found: ID {$device['id']}, Name: {$device['device_name']}\n";
        } else {
            echo "⚠ No device found with IP $device_ip - creating one...\n";
            
            // Create device record
            $insert_stmt = $conn->prepare('INSERT INTO hikvision_devices (church_id, device_name, ip_address, port, username, password, device_model) VALUES (1, ?, ?, 80, ?, ?, ?)');
            $device_name = 'Main Entrance Terminal';
            $username = 'admin';
            $password = '223344AD';
            $model = 'DS-K1T320MFWX';
            
            $insert_stmt->bind_param('sssss', $device_name, $device_ip, $username, $password, $model);
            
            if ($insert_stmt->execute()) {
                $device_id = $conn->insert_id;
                echo "✓ Device created successfully with ID: $device_id\n";
            } else {
                echo "✗ ERROR: Failed to create device record: " . $conn->error . "\n";
            }
            $insert_stmt->close();
        }
        $stmt->close();
    } else {
        echo "✗ hikvision_devices table does not exist\n";
    }
    
    // Check member_hikvision_data table
    $result = $conn->query("SHOW TABLES LIKE 'member_hikvision_data'");
    if ($result->num_rows > 0) {
        echo "✓ member_hikvision_data table exists\n";
    } else {
        echo "✗ member_hikvision_data table does not exist\n";
    }
    
    $conn->close();
    echo "\nSetup check complete!\n";
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}
?>
