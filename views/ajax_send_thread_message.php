<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);
$thread_id = isset($input['thread_id']) ? intval($input['thread_id']) : 0;
$message = isset($input['message']) ? trim($input['message']) : '';

if (!$thread_id || !$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Thread ID and message are required']);
    exit;
}

// Verify thread exists
$stmt = $conn->prepare('SELECT * FROM member_feedback_thread WHERE id = ? AND feedback_id IS NULL');
$stmt->bind_param('i', $thread_id);
$stmt->execute();
$thread = $stmt->get_result()->fetch_assoc();

if (!$thread) {
    http_response_code(404);
    echo json_encode(['error' => 'Thread not found']);
    exit;
}

// Determine sender type/id
if (isset($_SESSION['member_id'])) {
    $sender_type = 'member';
    $sender_id = $_SESSION['member_id'];
} else {
    $sender_type = 'user';
    $sender_id = $_SESSION['user_id'] ?? 0;
}

// Insert new message
$stmt = $conn->prepare('INSERT INTO member_feedback_thread (feedback_id, recipient_type, recipient_id, sender_type, sender_id, message, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
$stmt->bind_param('isisss', $thread_id, $thread['recipient_type'], $thread['recipient_id'], $sender_type, $sender_id, $message);

if ($stmt->execute()) {
    $message_id = $conn->insert_id;
    
    // Get sender name
    if ($sender_type === 'member') {
        $name_stmt = $conn->prepare('SELECT CONCAT(first_name, " ", last_name) as name FROM members WHERE id = ?');
    } else {
        $name_stmt = $conn->prepare('SELECT name FROM users WHERE id = ?');
    }
    $name_stmt->bind_param('i', $sender_id);
    $name_stmt->execute();
    $sender_name = $name_stmt->get_result()->fetch_assoc()['name'] ?? 'Unknown';
    
    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'message' => [
            'id' => $message_id,
            'message' => $message,
            'sender_type' => $sender_type,
            'sender_id' => $sender_id,
            'sender_name' => $sender_name,
            'sent_at' => date('Y-m-d H:i:s'),
            'formatted_time' => date('M j, Y g:i A')
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send message']);
}
?>
