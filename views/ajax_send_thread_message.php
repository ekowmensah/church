<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';
require_once __DIR__.'/../helpers/member_feedback_access.php';
header('Content-Type: application/json; charset=utf-8');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
// Permission check
$has_feedback_permission = has_permission('view_feedback_report') || has_permission('view_dashboard');
$is_member_session = isset($_SESSION['member_id']);
if (!$is_member_session && !$has_feedback_permission) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
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
$input = is_array($input) ? $input : [];
if (!csrf_is_valid($input['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Your session token is invalid. Refresh the page and try again.']);
    exit;
}
$thread_id = isset($input['thread_id']) ? intval($input['thread_id']) : 0;
$message = isset($input['message']) ? trim($input['message']) : '';
$message = mb_substr($message, 0, 4000);

if (!$thread_id || !$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Thread ID and message are required']);
    exit;
}

// Verify thread exists
$thread = member_feedback_load_thread($conn, $thread_id);

if (!$thread) {
    http_response_code(404);
    echo json_encode(['error' => 'Thread not found']);
    exit;
}

// Determine sender type/id
$actor = member_feedback_actor();
$sender_type = $actor['type'];
$sender_id = $actor['id'];
$recipient = member_feedback_reply_recipient($thread, $sender_type, $sender_id);

// Insert new message
$stmt = $conn->prepare('INSERT INTO member_feedback_thread (feedback_id, recipient_type, recipient_id, sender_type, sender_id, message, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
$stmt->bind_param('isisis', $thread_id, $recipient['type'], $recipient['id'], $sender_type, $sender_id, $message);

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
