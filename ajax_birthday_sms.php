<?php
/**
 * Manual Birthday SMS Trigger
 * 
 * This endpoint allows administrators to manually trigger birthday SMS
 * for testing or to send birthday messages on-demand.
 */

session_start();
require_once 'config/config.php';
require_once 'helpers/auth.php';
require_once 'helpers/permissions.php';
require_once 'includes/sms.php';

// Check authentication and permissions
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

// Check if user has SMS permissions
if (!$is_super_admin && !has_permission('send_sms')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Insufficient permissions']);
    exit;
}

header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_birthday_members':
            echo json_encode(get_birthday_members_today());
            break;
            
        case 'send_birthday_sms':
            $member_id = $_POST['member_id'] ?? null;
            if ($member_id) {
                echo json_encode(send_birthday_sms_to_member($member_id));
            } else {
                echo json_encode(send_birthday_sms_to_all());
            }
            break;
            
        case 'test_birthday_sms':
            $phone = $_POST['phone'] ?? '';
            $name = $_POST['name'] ?? 'Test User';
            if ($phone) {
                echo json_encode(send_test_birthday_sms($phone, $name));
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Phone number required']);
            }
            break;
            
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("Birthday SMS Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error occurred']);
}

/**
 * Get members whose birthday is today
 */
function get_birthday_members_today() {
    global $conn;
    
    $today = date('m-d');
    
    $query = "
        SELECT 
            id,
            first_name,
            last_name,
            phone,
            dob,
            church_id,
            CONCAT(first_name, ' ', last_name) as full_name,
            DATE_FORMAT(dob, '%M %e') as birthday_formatted
        FROM members 
        WHERE 
            dob IS NOT NULL 
            AND dob != '0000-00-00' 
            AND dob != '' 
            AND DATE_FORMAT(dob, '%m-%d') = ?
            AND phone IS NOT NULL 
            AND phone != ''
            AND phone != '0'
        ORDER BY first_name, last_name
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    
    return [
        'status' => 'success',
        'count' => count($members),
        'members' => $members,
        'date' => date('Y-m-d'),
        'formatted_date' => date('F j, Y')
    ];
}

/**
 * Send birthday SMS to a specific member
 */
function send_birthday_sms_to_member($member_id) {
    global $conn;
    
    // Get member details
    $stmt = $conn->prepare("
        SELECT 
            id, first_name, last_name, phone, dob, church_id,
            CONCAT(first_name, ' ', last_name) as full_name
        FROM members 
        WHERE id = ? AND phone IS NOT NULL AND phone != '' AND phone != '0'
    ");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$member = $result->fetch_assoc()) {
        return ['status' => 'error', 'message' => 'Member not found or no phone number'];
    }
    
    // Get church name
    $church_name = get_church_name($member['church_id']);
    
    // Birthday message
    $message = "Happy Birthday, {name}!\n\nAs you celebrate another year of God's faithfulness, we wish you all the good things you desire in life. May the Grace and Favour of God be multiplied unto you. Enjoy your special day, and stay blessed.\n\n{church_name}.";
    
    $template_data = [
        'name' => $member['first_name'],
        'full_name' => $member['full_name'],
        'church_name' => $church_name
    ];
    
    // Send SMS
    $sms_result = send_sms(
        $member['phone'], 
        $message, 
        null,
        $template_data
    );
    
    // Log to database
    log_birthday_sms_to_db($member, $message, $template_data, $sms_result);
    
    return [
        'status' => $sms_result['status'] ?? 'error',
        'message' => $sms_result['message'] ?? 'Unknown error',
        'member' => $member['full_name'],
        'phone' => $member['phone'],
        'sms_result' => $sms_result
    ];
}

/**
 * Send birthday SMS to all members with birthdays today
 */
function send_birthday_sms_to_all() {
    $birthday_data = get_birthday_members_today();
    
    if ($birthday_data['count'] == 0) {
        return ['status' => 'info', 'message' => 'No members have birthdays today'];
    }
    
    $results = [];
    $success_count = 0;
    $error_count = 0;
    
    foreach ($birthday_data['members'] as $member) {
        $result = send_birthday_sms_to_member($member['id']);
        $results[] = $result;
        
        if ($result['status'] === 'success') {
            $success_count++;
        } else {
            $error_count++;
        }
        
        // Small delay to avoid rate limiting
        usleep(500000); // 0.5 seconds
    }
    
    return [
        'status' => 'completed',
        'total_members' => $birthday_data['count'],
        'success_count' => $success_count,
        'error_count' => $error_count,
        'results' => $results
    ];
}

/**
 * Send test birthday SMS
 */
function send_test_birthday_sms($phone, $name) {
    $church_name = 'Freeman Methodist Church, Kwesimintsim';
    
    $message = "Happy Birthday, {name}!\n\nAs you celebrate another year of God's faithfulness, we wish you all the good things you desire in life. May the Grace and Favour of God be multiplied unto you. Enjoy your special day, and stay blessed.\n\n{church_name}.\n\n[TEST MESSAGE]";
    
    $template_data = [
        'name' => $name,
        'church_name' => $church_name
    ];
    
    $result = send_sms($phone, $message, null, $template_data);
    
    return [
        'status' => $result['status'] ?? 'error',
        'message' => $result['message'] ?? 'Unknown error',
        'phone' => $phone,
        'name' => $name,
        'sms_result' => $result
    ];
}

/**
 * Get church name by ID
 */
function get_church_name($church_id) {
    global $conn;
    
    if (empty($church_id)) {
        return 'Freeman Methodist Church, Kwesimintsim';
    }
    
    $stmt = $conn->prepare("SELECT name FROM churches WHERE id = ?");
    $stmt->bind_param('i', $church_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['name'];
    }
    
    return 'Freeman Methodist Church, Kwesimintsim';
}

/**
 * Log birthday SMS to database
 */
function log_birthday_sms_to_db($member, $message, $template_data, $result) {
    global $conn;
    
    // Process the message template
    $processed_message = process_template($message, $template_data);
    
    // Get SMS config for provider info
    $sms_config = get_sms_config();
    $provider = $sms_config ? $sms_config['default_provider'] : 'unknown';
    
    $status = (isset($result['status']) && $result['status'] === 'success') ? 'success' : 'fail';
    $response = json_encode($result, JSON_PRETTY_PRINT);
    
    // Ensure type column exists
    try {
        $conn->query("SELECT type FROM sms_logs LIMIT 1");
    } catch (Exception $e) {
        // Add type column if it doesn't exist
        $conn->query("ALTER TABLE sms_logs ADD COLUMN type VARCHAR(50) DEFAULT 'general' AFTER template_name");
    }
    
    // Insert into sms_logs table
    $stmt = $conn->prepare('
        INSERT INTO sms_logs 
        (member_id, phone, message, template_name, type, status, provider, sent_at, response) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ');
    
    $template_name = 'birthday_sms';
    $type = 'birthday';
    
    $stmt->bind_param(
        'isssssss', 
        $member['id'], 
        $member['phone'], 
        $processed_message, 
        $template_name, 
        $type, 
        $status, 
        $provider, 
        $response
    );
    
    $stmt->execute();
}
?>
