<?php
// api/hikvision/push-users.php
// Receives device user mapping data from the agent

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

$API_KEY = getenv('HIKVISION_AGENT_API_KEY') ?: 'replace_this_with_a_real_key';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_GET['key'] ?? '') !== $API_KEY) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['users'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

$conn = get_db_connection();
$success = true;
$errors = [];

foreach ($input['users'] as $user) {
    // Expected: device_id, hikvision_user_id, member_id
    $device_id = $user['device_id'] ?? null;
    $hikvision_user_id = $user['hikvision_user_id'] ?? null;
    $member_id = $user['member_id'] ?? null;
    if (!$device_id || !$hikvision_user_id || !$member_id) {
        $errors[] = ['user' => $user, 'error' => 'Missing required fields'];
        $success = false;
        continue;
    }
    // Insert or update mapping
    $stmt = $conn->prepare('REPLACE INTO member_hikvision_data (member_id, device_id, hikvision_user_id) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $member_id, $device_id, $hikvision_user_id);
    $stmt->execute();
    $stmt->close();
}
$conn->close();

if ($success) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'errors' => $errors]);
}
