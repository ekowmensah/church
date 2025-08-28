<?php
// api/hikvision/push-logs.php
// Receives attendance logs from the local sync agent and updates attendance records

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/attendance.php';

header('Content-Type: application/json');

// Simple API key check for agent authentication
$API_KEY = '0c6c5401ab9f1af81c7cbadee3279663a918a16407fbc84a0d4bd189789d9f49';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_GET['key'] ?? '') !== $API_KEY) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['logs'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

$conn = get_db_connection();
$success = true;
$errors = [];

foreach ($input['logs'] as $log) {
    // Expected: device_id, hikvision_user_id, timestamp, event_type
    $device_id = $log['device_id'] ?? null;
    $hikvision_user_id = $log['hikvision_user_id'] ?? null;
    $timestamp = $log['timestamp'] ?? null;
    $event_type = $log['event_type'] ?? 'fingerprint';

    if (!$device_id || !$hikvision_user_id || !$timestamp) {
        $errors[] = ['log' => $log, 'error' => 'Missing required fields'];
        $success = false;
        continue;
    }

    // Find mapped member
    $stmt = $conn->prepare('SELECT member_id FROM member_hikvision_data WHERE device_id = ? AND hikvision_user_id = ?');
    $stmt->bind_param('ss', $device_id, $hikvision_user_id);
    $stmt->execute();
    $stmt->bind_result($member_id);
    if (!$stmt->fetch()) {
        $errors[] = ['log' => $log, 'error' => 'No member mapping'];
        $success = false;
        $stmt->close();
        continue;
    }
    $stmt->close();

    // Insert raw log (note: column name is 'user_id' not 'hikvision_user_id' based on schema)
    $stmt = $conn->prepare('INSERT INTO hikvision_raw_logs (device_id, user_id, event_time, event_type) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $device_id, $hikvision_user_id, $timestamp, $event_type);
    $stmt->execute();
    $raw_log_id = $stmt->insert_id;
    $stmt->close();

    // Insert or update attendance record
    $session_id = get_or_create_attendance_session($timestamp, $conn); // Helper function to map timestamp to session
    $stmt = $conn->prepare('REPLACE INTO attendance_records (member_id, session_id, status, sync_source, hikvision_raw_log_id, device_timestamp) VALUES (?, ?, ?, ?, ?, ?)');
    $status = 'present';
    $sync_source = 'hikvision';
    $device_timestamp = $timestamp;
    $stmt->bind_param('iissis', $member_id, $session_id, $status, $sync_source, $raw_log_id, $device_timestamp);
    $stmt->execute();
    $stmt->close();
}

$conn->close();

if ($success) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'errors' => $errors]);
}
