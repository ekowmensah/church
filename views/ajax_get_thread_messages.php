<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get thread id and last message timestamp
$thread_id = isset($_GET['thread_id']) ? intval($_GET['thread_id']) : 0;
$last_timestamp = isset($_GET['last_timestamp']) ? $_GET['last_timestamp'] : '1970-01-01 00:00:00';

if (!$thread_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Thread ID required']);
    exit;
}

// Verify thread exists and user has access
$stmt = $conn->prepare('SELECT * FROM member_feedback_thread WHERE id = ? AND feedback_id IS NULL');
$stmt->bind_param('i', $thread_id);
$stmt->execute();
$thread = $stmt->get_result()->fetch_assoc();

if (!$thread) {
    http_response_code(404);
    echo json_encode(['error' => 'Thread not found']);
    exit;
}

// Fetch new messages since last timestamp
$stmt = $conn->prepare('
    SELECT mft.*, 
           CASE 
               WHEN mft.sender_type = "member" THEN CONCAT(m.first_name, " ", m.last_name)
               WHEN mft.sender_type = "user" THEN u.name
               ELSE "Unknown"
           END as sender_name
    FROM member_feedback_thread mft
    LEFT JOIN members m ON mft.sender_type = "member" AND mft.sender_id = m.id
    LEFT JOIN users u ON mft.sender_type = "user" AND mft.sender_id = u.id
    WHERE (mft.id = ? OR mft.feedback_id = ?) 
    AND mft.sent_at > ?
    ORDER BY mft.sent_at ASC, mft.id ASC
');
$stmt->bind_param('iis', $thread_id, $thread_id, $last_timestamp);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
$latest_timestamp = $last_timestamp;

while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => $row['id'],
        'message' => $row['message'],
        'sender_type' => $row['sender_type'],
        'sender_id' => $row['sender_id'],
        'sender_name' => $row['sender_name'],
        'sent_at' => $row['sent_at'],
        'formatted_time' => date('M j, Y g:i A', strtotime($row['sent_at']))
    ];
    $latest_timestamp = $row['sent_at'];
}

echo json_encode([
    'success' => true,
    'messages' => $messages,
    'latest_timestamp' => $latest_timestamp,
    'has_new_messages' => count($messages) > 0
]);
?>
