<?php
/**
 * Sync Hikvision Device Users
 * Pulls enrolled users from the Hikvision device and adds them to the database for mapping
 */

$conn = require_once __DIR__ . '/config/config.php';

// Device configuration
$device_ip = '192.168.5.201';
$device_port = 80;
$device_username = 'admin';
$device_password = '223344AD';

// Get device ID from database
$device_stmt = $conn->prepare('SELECT id FROM hikvision_devices WHERE ip_address = ?');
$device_stmt->bind_param('s', $device_ip);
$device_stmt->execute();
$device_result = $device_stmt->get_result();

if ($device_result->num_rows == 0) {
    echo "ERROR: Device with IP $device_ip not found in hikvision_devices table\n";
    echo "Please add the device through the HikVision Devices management page first\n";
    exit(1);
}

$device_row = $device_result->fetch_assoc();
$device_id = $device_row['id'];
$device_stmt->close();
$conn->close();

echo "Syncing users from Hikvision device at $device_ip...\n";
echo "Using device ID: $device_id\n";

// Get users from device via ISAPI
$url = "http://{$device_ip}:{$device_port}/ISAPI/AccessControl/UserInfo/Search?format=json";

$json_body = json_encode([
    "UserInfoSearchCond" => [
        "searchID" => "1",
        "searchResultPosition" => 0,
        "maxResults" => 100
    ]
]);

echo "Requesting users from device: $url\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);

$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "ERROR: Failed to connect to device: $error\n";
    exit(1);
}

echo "Device response:\n$response\n\n";

$response_data = json_decode($response, true);

if (!$response_data || !isset($response_data['UserInfoSearch'])) {
    echo "ERROR: Invalid response from device\n";
    exit(1);
}

$users_data = $response_data['UserInfoSearch'];

if (isset($users_data['numOfMatches']) && $users_data['numOfMatches'] == 0) {
    echo "No users found on device\n";
    exit(0);
}

// Process users
$users_added = 0;

if (isset($users_data['UserInfo'])) {
    $users = is_array($users_data['UserInfo'][0]) ? $users_data['UserInfo'] : [$users_data['UserInfo']];
    
    foreach ($users as $user) {
        $hikvision_user_id = $user['employeeNo'] ?? $user['userID'] ?? null;
        $user_name = $user['name'] ?? 'Unknown User';
        
        if (!$hikvision_user_id) {
            echo "Skipping user with no ID\n";
            continue;
        }
        
        echo "Processing user: $hikvision_user_id ($user_name)\n";
        
        // Check if user already exists
        $check_stmt = $conn->prepare('SELECT id FROM member_hikvision_data WHERE device_id = ? AND hikvision_user_id = ?');
        $check_stmt->bind_param('is', $device_id, $hikvision_user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            echo "User $hikvision_user_id already exists in database\n";
            $check_stmt->close();
            continue;
        }
        $check_stmt->close();
        
        // Insert new user (without member_id since it's for mapping later)
        $insert_stmt = $conn->prepare('INSERT INTO member_hikvision_data (device_id, hikvision_user_id, created_at) VALUES (?, ?, NOW())');
        $insert_stmt->bind_param('is', $device_id, $hikvision_user_id);
        
        if ($insert_stmt->execute()) {
            echo "Added user: $hikvision_user_id\n";
            $users_added++;
        } else {
            echo "ERROR: Failed to add user $hikvision_user_id\n";
        }
        $insert_stmt->close();
    }
}

$conn->close();

echo "\nSync completed. Added $users_added new users.\n";
echo "Go to User Mapping interface to map these users to church members.\n";
?>
