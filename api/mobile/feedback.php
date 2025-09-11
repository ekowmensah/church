<?php
/**
 * Mobile API Feedback Endpoint
 * Handles member feedback/chat functionality
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/jwt_helper.php';

// Authenticate request
$auth = authenticate_mobile_request();
if (!$auth) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$member_id = $auth['member_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get feedback threads for member
        $thread_id = $_GET['thread_id'] ?? null;
        
        if ($thread_id) {
            // Get specific thread messages
            $stmt = $conn->prepare("
                SELECT t.id, t.sender_type, t.sender_id, t.recipient_type, t.recipient_id, 
                       t.message, t.sent_at, t.feedback_id
                FROM member_feedback_thread t
                WHERE (t.id = ? OR t.feedback_id = ?) 
                AND ((t.sender_type = 'member' AND t.sender_id = ?) OR 
                     (t.recipient_type = 'member' AND t.recipient_id = ?))
                ORDER BY t.sent_at ASC
            ");
            $stmt->bind_param('iiii', $thread_id, $thread_id, $member_id, $member_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $messages = [];
            while ($row = $result->fetch_assoc()) {
                $is_sent_by_me = ($row['sender_type'] === 'member' && $row['sender_id'] == $member_id);
                
                $messages[] = [
                    'id' => intval($row['id']),
                    'message' => $row['message'],
                    'sent_at' => $row['sent_at'],
                    'is_sent_by_me' => $is_sent_by_me,
                    'sender_type' => $row['sender_type']
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'thread_id' => intval($thread_id),
                    'messages' => $messages
                ]
            ]);
            
        } else {
            // Get all feedback threads for member
            $stmt = $conn->prepare("
                SELECT t.id, t.sender_type, t.sender_id, t.recipient_type, t.recipient_id, 
                       t.message, t.sent_at,
                       CASE WHEN t.sender_type = 'member' AND t.sender_id != ? THEN t.sender_id
                            WHEN t.recipient_type = 'member' AND t.recipient_id != ? THEN t.recipient_id
                            ELSE NULL END AS contact_member_id
                FROM member_feedback_thread t
                WHERE t.feedback_id IS NULL AND (
                    (t.sender_type = 'member' AND t.sender_id = ?) OR
                    (t.recipient_type = 'member' AND t.recipient_id = ?)
                )
                ORDER BY t.sent_at DESC
            ");
            $stmt->bind_param('iiii', $member_id, $member_id, $member_id, $member_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $threads = [];
            $contact_ids = [];
            
            while ($row = $result->fetch_assoc()) {
                if ($row['contact_member_id']) {
                    $contact_ids[] = $row['contact_member_id'];
                }
                $threads[] = $row;
            }
            
            // Get contact member names
            $contacts = [];
            if (!empty($contact_ids)) {
                $contact_ids = array_unique($contact_ids);
                $in = implode(',', array_fill(0, count($contact_ids), '?'));
                $types = str_repeat('i', count($contact_ids));
                
                $stmt = $conn->prepare("SELECT id, CONCAT_WS(' ', first_name, middle_name, last_name) AS full_name, photo FROM members WHERE id IN ($in)");
                $stmt->bind_param($types, ...$contact_ids);
                $stmt->execute();
                $res = $stmt->get_result();
                
                while ($contact = $res->fetch_assoc()) {
                    $photo_url = null;
                    if (!empty($contact['photo']) && file_exists(__DIR__ . '/../../uploads/members/' . $contact['photo'])) {
                        $photo_url = BASE_URL . '/uploads/members/' . rawurlencode($contact['photo']);
                    }
                    
                    $contacts[$contact['id']] = [
                        'name' => trim($contact['full_name']),
                        'photo_url' => $photo_url
                    ];
                }
            }
            
            // Format threads for mobile
            $formatted_threads = [];
            foreach ($threads as $thread) {
                $contact_name = 'Church Admin';
                $contact_photo = null;
                
                if ($thread['contact_member_id'] && isset($contacts[$thread['contact_member_id']])) {
                    $contact = $contacts[$thread['contact_member_id']];
                    $contact_name = $contact['name'];
                    $contact_photo = $contact['photo_url'];
                } elseif ($thread['sender_type'] === 'user' || $thread['recipient_type'] === 'user') {
                    $contact_name = 'Church Admin';
                }
                
                $formatted_threads[] = [
                    'id' => intval($thread['id']),
                    'contact_name' => $contact_name,
                    'contact_photo_url' => $contact_photo,
                    'last_message' => $thread['message'],
                    'last_sent_at' => $thread['sent_at'],
                    'is_sent_by_me' => ($thread['sender_type'] === 'member' && $thread['sender_id'] == $member_id)
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'threads' => $formatted_threads
                ]
            ]);
        }
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Send new message or create new thread
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['message']) || empty(trim($input['message']))) {
            http_response_code(400);
            echo json_encode(['error' => 'Message is required']);
            exit();
        }
        
        $message = trim($input['message']);
        $thread_id = $input['thread_id'] ?? null;
        $recipient_type = $input['recipient_type'] ?? 'user'; // Default to admin
        $recipient_id = $input['recipient_id'] ?? 1; // Default admin user ID
        
        if ($thread_id) {
            // Reply to existing thread
            $stmt = $conn->prepare("
                INSERT INTO member_feedback_thread (feedback_id, sender_type, sender_id, recipient_type, recipient_id, message, sent_at)
                VALUES (?, 'member', ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('iisis', $thread_id, $member_id, $recipient_type, $recipient_id, $message);
            
        } else {
            // Create new thread
            $stmt = $conn->prepare("
                INSERT INTO member_feedback_thread (sender_type, sender_id, recipient_type, recipient_id, message, sent_at)
                VALUES ('member', ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('isis', $member_id, $recipient_type, $recipient_id, $message);
        }
        
        if ($stmt->execute()) {
            $message_id = $conn->insert_id;
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'message_id' => $message_id,
                    'thread_id' => $thread_id ?: $message_id
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to send message']);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Mobile feedback error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
