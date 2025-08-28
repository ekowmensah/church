<?php
$conn = require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

echo "Testing HikVision Attendance Sync...\n\n";

// Device configuration
$device_ip = '192.168.5.201';
$device_port = 80;
$device_username = 'admin';
$device_password = '223344AD';

// Get recent attendance logs from device
$url = "http://{$device_ip}:{$device_port}/ISAPI/AccessControl/AcsEvent?format=json";

$startTime = date('Y-m-d\TH:i:s', strtotime('-7 days'));
$endTime = date('Y-m-d\TH:i:s');

$json_body = json_encode([
    "AcsEventCond" => [
        "searchID" => "1",
        "searchResultPosition" => 0,
        "startTime" => $startTime,
        "endTime" => $endTime,
        "maxResults" => 100,
        "major" => 5,
        "minor" => 75
    ]
]);

echo "Searching for attendance logs from $startTime to $endTime...\n";

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
    exit(1);
}

if ($http_code !== 200) {
    echo "✗ HTTP Error: $http_code\n";
    echo "Response: $response\n";
    exit(1);
}

echo "✓ Successfully connected to device\n";

$response_data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "✗ JSON decode error: " . json_last_error_msg() . "\n";
    echo "Raw response: $response\n";
    exit(1);
}

echo "✓ JSON response parsed successfully\n";

if (isset($response_data['AcsEvent'])) {
    $events_data = $response_data['AcsEvent'];
    
    if (isset($events_data['numOfMatches']) && $events_data['numOfMatches'] == 0) {
        echo "⚠ No attendance events found in the specified time range\n";
        echo "To test the integration:\n";
        echo "1. Have someone use face recognition or card access on the device\n";
        echo "2. Run this test again to see the attendance logs\n";
        echo "3. Run the sync agent to process the logs\n";
    } else {
        echo "✓ Found attendance events!\n";
        
        if (isset($events_data['InfoList'])) {
            $events = is_array($events_data['InfoList'][0]) ? $events_data['InfoList'] : [$events_data['InfoList']];
            echo "Processing " . count($events) . " events...\n\n";
            
            foreach ($events as $event) {
                $employeeNo = $event['employeeNoString'] ?? 'Unknown';
                $time = $event['time'] ?? 'Unknown';
                $major = $event['major'] ?? 0;
                $minor = $event['minor'] ?? 0;
                
                echo "- Event: User $employeeNo at $time (Type: $major/$minor)\n";
            }
            
            echo "\n✓ Ready to process attendance events!\n";
            echo "Next steps:\n";
            echo "1. Map HikVision users to church members via User Mapping interface\n";
            echo "2. Run the sync agent: php hikvision_sync_agent.php\n";
            echo "3. Check attendance records in the system\n";
        }
    }
} else {
    echo "✗ Unexpected response format\n";
    echo "Response: " . print_r($response_data, true) . "\n";
}

$conn->close();
?>
