<?php
/**
 * Hikvision Local Sync Agent (PHP)
 *
 * Usage: Run this script on a local PC/server with PHP CLI. Schedule via cron or Task Scheduler for periodic sync.
 *
 * Features:
 * - Polls Hikvision device for attendance logs (ISAPI)
 * - Sends new logs to cloud API endpoint securely (API key)
 * - Tracks last sync to avoid duplicates
 */

// === CONFIGURATION ===
$device_ip = '192.168.5.201'; // Hikvision device IP
$device_port = 80;
$username = 'admin';
$password = '223344AD';
$cloud_api_url = 'http://localhost/myfreemanchurchgit/church/api_hikvision_attendance.php';
$api_key = '0c6c5401ab9f1af81c7cbadee3279663a918a16407fbc84a0d4bd189789d9f49';
$last_sync_file = __DIR__ . '/hikvision_last_sync.txt';

// === LOAD LAST SYNC TIME ===
$last_sync_time = @file_get_contents($last_sync_file);
if (!$last_sync_time) {
    $last_sync_time = date('Y-m-d\TH:i:s', strtotime('-1 day'));
}

// === FETCH ATTENDANCE LOGS FROM DEVICE ===
$endpoint_post = "/ISAPI/AccessControl/AcsEvent?format=json";
$url_post = "http://{$device_ip}:{$device_port}{$endpoint_post}";
$startTime = $last_sync_time;
$endTime = date('Y-m-d\TH:i:s');

// Use JSON format instead of XML (device expects JSON based on error)
$json_body = json_encode([
    "AcsEventCond" => [
        "searchID" => "1",
        "searchResultPosition" => 0,
        "startTime" => $startTime,
        "endTime" => $endTime,
        "maxResults" => 1000,
        "major" => 5,  // Access control events
        "minor" => 75  // Card reader events
    ]
]);

echo "[DEBUG] JSON body: $json_body\n";
file_put_contents(__DIR__ . '/debug_json_body.txt', $json_body);
// === TRY JSON FORMAT ===
echo "\n[DEBUG] POSTing to: $url_post\n";
echo "[DEBUG] Request body (JSON):\n$json_body\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_post);
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
echo "[DEBUG] Raw response (POST JSON):\n$response\n";
curl_close($ch);

if ($error) {
    echo "[ERROR] Failed to connect to device (POST JSON): $error\n";
    exit(1);
}

$response_data = json_decode($response, true);
if ($response_data && isset($response_data['AcsEvent'])) {
    echo "[DEBUG] Success with POST (JSON)\n";
    
    $events = $response_data['AcsEvent'];
    
    // Check if there are actually any events to process
    if (isset($events['numOfMatches']) && $events['numOfMatches'] == 0) {
        echo "[INFO] No new logs to sync (numOfMatches: 0).\n";
        file_put_contents($last_sync_file, $endTime);
        exit(0);
    }
    
    // Extract events and push to cPanel API
    $logs = [];
    
    // Handle both single event and array of events
    if (isset($events['InfoList'])) {
        // Multiple events in InfoList
        foreach ($events['InfoList'] as $event) {
            $logs[] = [
                'device_id' => $device_ip,
                'hikvision_user_id' => $event['employeeNoString'] ?? $event['cardNo'] ?? null,
                'timestamp' => $event['time'] ?? null,
                'event_type' => 'access_control'
            ];
        }
    } elseif (isset($events['time'])) {
        // Single event with actual data
        $logs[] = [
            'device_id' => $device_ip,
            'hikvision_user_id' => $events['employeeNoString'] ?? $events['cardNo'] ?? null,
            'timestamp' => $events['time'] ?? null,
            'event_type' => 'access_control'
        ];
    }
    
    if (!empty($logs)) {
        $payload = json_encode(['logs' => $logs]);
        $ch_api = curl_init("http://localhost/myfreemanchurchgit/church/api/hikvision/push-logs.php?key=$api_key");
        curl_setopt($ch_api, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_api, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch_api, CURLOPT_POST, true);
        curl_setopt($ch_api, CURLOPT_POSTFIELDS, $payload);
        $api_resp = curl_exec($ch_api);
        $api_err = curl_error($ch_api);
        curl_close($ch_api);
        if ($api_err) {
            echo "[ERROR] Failed to push logs to cPanel: $api_err\n";
        } else {
            echo "[INFO] Pushed ".count($logs)." logs to cPanel.\n";
            file_put_contents($last_sync_file, $endTime);
        }
    } else {
        echo "[INFO] No new logs to sync.\n";
    }
    exit(0);
}

// If JSON format failed, show error details
echo "[ERROR] JSON request failed. Response: $response\n";
exit(1);
