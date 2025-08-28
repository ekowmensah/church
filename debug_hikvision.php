<?php
$conn = require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

echo "Testing HikVision setup...\n";

try {
    if ($conn && $conn instanceof mysqli) {
        echo "✓ Database connection successful\n";
    } else {
        throw new Exception("Database connection failed");
    }
    
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
            
            // Now try to sync users
            echo "\n--- Starting User Sync ---\n";
            
            $device_id = $device['id'];
            $device_port = 80;
            $device_username = 'admin';
            $device_password = '223344AD';
            
            // Get users from device via ISAPI
            $url = "http://{$device_ip}:{$device_port}/ISAPI/AccessControl/UserInfo/Search?format=json";
            
            $json_body = json_encode([
                "UserInfoSearchCond" => [
                    "searchID" => "1",
                    "searchResultPosition" => 0,
                    "maxResults" => 100
                ]
            ]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json_body)
            ]);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            curl_setopt($ch, CURLOPT_USERPWD, $device_username . ':' . $device_password);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($curl_error) {
                echo "✗ CURL Error: $curl_error\n";
            } elseif ($http_code !== 200) {
                echo "✗ HTTP Error: $http_code\n";
                echo "Response: $response\n";
            } else {
                echo "✓ Successfully connected to device\n";
                $response_data = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo "✗ JSON decode error: " . json_last_error_msg() . "\n";
                    echo "Raw response: $response\n";
                } else {
                    echo "✓ JSON response parsed successfully\n";
                    
                    if (isset($response_data['UserInfoSearch'])) {
                        $users_data = $response_data['UserInfoSearch'];
                        
                        if (isset($users_data['numOfMatches']) && $users_data['numOfMatches'] == 0) {
                            echo "⚠ No users found on device\n";
                        } else {
                            echo "✓ Found users on device\n";
                            
                            if (isset($users_data['UserInfo'])) {
                                $users = is_array($users_data['UserInfo'][0]) ? $users_data['UserInfo'] : [$users_data['UserInfo']];
                                echo "Processing " . count($users) . " users...\n";
                                
                                $users_added = 0;
                                foreach ($users as $user) {
                                    $hikvision_user_id = $user['employeeNo'] ?? $user['userID'] ?? null;
                                    $user_name = $user['name'] ?? 'Unknown User';
                                    
                                    if (!$hikvision_user_id) {
                                        echo "- Skipping user with no ID\n";
                                        continue;
                                    }
                                    
                                    echo "- Processing user: $hikvision_user_id ($user_name)\n";
                                    
                                    // Check if user already exists
                                    $check_stmt = $conn->prepare('SELECT id FROM member_hikvision_data WHERE device_id = ? AND hikvision_user_id = ?');
                                    $check_stmt->bind_param('is', $device_id, $hikvision_user_id);
                                    $check_stmt->execute();
                                    $check_result = $check_stmt->get_result();
                                    
                                    if ($check_result->num_rows > 0) {
                                        echo "  - User already exists in database\n";
                                        $check_stmt->close();
                                        continue;
                                    }
                                    $check_stmt->close();
                                    
                                    // Insert new user
                                    $insert_stmt = $conn->prepare('INSERT INTO member_hikvision_data (device_id, hikvision_user_id, created_at) VALUES (?, ?, NOW())');
                                    $insert_stmt->bind_param('is', $device_id, $hikvision_user_id);
                                    
                                    if ($insert_stmt->execute()) {
                                        echo "  - ✓ Added user: $hikvision_user_id\n";
                                        $users_added++;
                                    } else {
                                        echo "  - ✗ Failed to add user $hikvision_user_id: " . $conn->error . "\n";
                                    }
                                    $insert_stmt->close();
                                }
                                
                                echo "\n✓ Sync complete! Added $users_added new users.\n";
                            }
                        }
                    } else {
                        echo "✗ Unexpected response format\n";
                        echo "Response: " . print_r($response_data, true) . "\n";
                    }
                }
            }
            
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
                echo "Please run the sync again to pull users from the device.\n";
            } else {
                echo "✗ ERROR: Failed to create device record: " . $conn->error . "\n";
            }
            $insert_stmt->close();
        }
        $stmt->close();
    } else {
        echo "✗ hikvision_devices table does not exist\n";
        echo "Please run the migration: migrations/20250817_create_hikvision_devices_table.sql\n";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}
?>
