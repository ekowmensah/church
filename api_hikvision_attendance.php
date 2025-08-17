<?php
// api_hikvision_attendance.php
// Receives attendance logs from the local sync agent and inserts into attendance_records

require_once __DIR__ . '/config/database.php';

// === CONFIGURATION ===
$EXPECTED_API_KEY = getenv('HIKVISION_API_KEY') ?: '0c6c5401ab9f1af81c7cbadee3279663a918a16407fbc84a0d4bd189789d9f49'; // Store securely in .env

header('Content-Type: application/json');

// === AUTHENTICATION ===
$headers = getallheaders();
$api_key = $headers['x-api-key'] ?? ($_POST['api_key'] ?? '');
if ($api_key !== $EXPECTED_API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid API key']);
    exit;
}

// === PARSE INPUT ===
$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data || empty($data['logs']) || !is_array($data['logs'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No logs received']);
    exit;
}
$logs = $data['logs'];

// === INSERT LOGS ===
$inserted = 0;
foreach ($logs as $log) {
    // Map Hikvision log fields to your DB schema
    $member_id = $log['employeeNoString'] ?? null;
    $device_id = $log['deviceNo'] ?? null;
    $event_time = $log['eventTime'] ?? null;
    $event_type = $log['eventType'] ?? '';
    $attendance_status = ($event_type === 'attendance' || $event_type === 'checkIn') ? 'present' : 'unknown';

    // Only insert if required fields are present
    if (!$member_id || !$device_id || !$event_time) continue;

    // Prepare and execute insert
    $stmt = $conn->prepare("INSERT INTO attendance_records (member_id, device_id, device_timestamp, status, sync_source, verification_type) VALUES (?, ?, ?, ?, 'hikvision', ?)");
    $verification_type = $log['attendanceType'] ?? 'face';
    $stmt->bind_param('sisss', $member_id, $device_id, $event_time, $attendance_status, $verification_type);
    if ($stmt->execute()) {
        $inserted++;
    }
}

http_response_code(200);
echo json_encode(['success' => true, 'inserted' => $inserted]);
