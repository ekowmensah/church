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
$device_ip = '192.168.1.100'; // Hikvision device IP
$device_port = 80;
$username = 'admin';
$password = 'your_device_password';
$cloud_api_url = 'https://yourcloud.com/api_hikvision_attendance.php';
$api_key = 'YOUR_GENERATED_API_KEY';
$last_sync_file = __DIR__ . '/hikvision_last_sync.txt';

// === LOAD LAST SYNC TIME ===
$last_sync_time = @file_get_contents($last_sync_file);
if (!$last_sync_time) {
    $last_sync_time = date('Y-m-d\TH:i:s', strtotime('-1 day'));
}

// === FETCH ATTENDANCE LOGS FROM DEVICE ===
$endpoint = "/ISAPI/AccessControl/AcsEvent?format=json&startTime={$last_sync_time}";
$url = "http://{$device_ip}:{$device_port}{$endpoint}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "[ERROR] Failed to connect to device: $error\n";
    exit(1);
}

$data = json_decode($response, true);
if (!$data || !isset($data['AcsEvent'])) {
    echo "[ERROR] Invalid response from device: $response\n";
    exit(1);
}

$logs = $data['AcsEvent'];
if (empty($logs)) {
    echo "[INFO] No new logs to sync.\n";
    exit(0);
}

// === SEND LOGS TO CLOUD ===
$post_data = [
    'api_key' => $api_key, // Optional: can use header instead
    'logs' => $logs
];

$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, $cloud_api_url);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-key: ' . $api_key
]);
curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($post_data));
$cloud_response = curl_exec($ch2);
$cloud_error = curl_error($ch2);
curl_close($ch2);

if ($cloud_error) {
    echo "[ERROR] Failed to sync with cloud: $cloud_error\n";
    exit(1);
}

$cloud_result = json_decode($cloud_response, true);
if (!$cloud_result || empty($cloud_result['success'])) {
    echo "[ERROR] Cloud rejected logs: $cloud_response\n";
    exit(1);
}

// === UPDATE LAST SYNC TIME ===
$latest_time = end($logs)['eventTime'] ?? date('Y-m-d\TH:i:s');
file_put_contents($last_sync_file, $latest_time);

// === DONE ===
echo "[OK] Synced " . count($logs) . " logs to cloud. Last event: $latest_time\n";
exit(0);
