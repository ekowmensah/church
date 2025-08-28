<?php
$conn = require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

echo "Manual HikVision User Sync Test...\n";

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
    exit(1);
}

$device_row = $device_result->fetch_assoc();
$device_id = $device_row['id'];
$device_stmt->close();

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
    echo "CURL Error: $curl_error\n";
    exit(1);
}

if ($http_code !== 200) {
    echo "HTTP Error: $http_code\n";
    echo "Response: $response\n";
    exit(1);
}

$response_data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "JSON decode error: " . json_last_error_msg() . "\n";
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
    
    echo "Found " . count($users) . " users on device\n";
    
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
}

echo "\nSync complete! Added $users_added new users.\n";

// Verify the data was inserted
echo "\nVerifying inserted data...\n";
$verify_result = $conn->query("SELECT COUNT(*) as count FROM member_hikvision_data WHERE device_id = $device_id");
$count = $verify_result->fetch_assoc()['count'];
echo "Total users in database for device $device_id: $count\n";

$conn->close();
?>
