<?php
/**
 * Birthday SMS Sender Script
 * 
 * This script sends birthday SMS messages to church members on their birthdays.
 * It should be run daily via cron job or task scheduler.
 * 
 * Usage:
 * - Run via command line: php birthday_sms_sender.php
 * - Run via web browser: http://yoursite.com/scripts/birthday_sms_sender.php?key=YOUR_SECRET_KEY
 * - Schedule to run daily at 8:00 AM
 */

// Security check for web access
if (isset($_GET['key'])) {
    $secret_key = '1234567890'; // Change this to a secure key
    if ($_GET['key'] !== $secret_key) {
        http_response_code(403);
        die('Unauthorized access');
    }
}

// Include required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/sms.php';

// Set timezone
date_default_timezone_set('Africa/Accra');

/**
 * Log birthday SMS activity
 */
function log_birthday_activity($message, $type = 'info') {
    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . '/birthday_sms_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$type] $message" . PHP_EOL;
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    
    // Also log to console if running from command line
    if (php_sapi_name() === 'cli') {
        echo $log_entry;
    }
}

/**
 * Get members whose birthday is today
 */
function get_birthday_members() {
    global $conn;
    
    $today = date('m-d'); // Format: MM-DD
    
    $query = "
        SELECT 
            id,
            first_name,
            last_name,
            phone,
            dob,
            church_id,
            CONCAT(first_name, ' ', last_name) as full_name
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
    
    return $members;
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
 * Send birthday SMS to a member
 */
function send_birthday_sms($member) {
    $church_name = get_church_name($member['church_id']);
    
    // Birthday message template
    $message = "Happy Birthday, {name}!\n\nAs you celebrate another year of God's faithfulness, we wish you all the good things you desire in life. May the Grace and Favour of God be multiplied unto you. Enjoy your special day, and stay blessed.\n\n{church_name}.";
    
    // Template data for processing
    $template_data = [
        'name' => $member['first_name'],
        'full_name' => $member['full_name'],
        'church_name' => $church_name
    ];
    
    // Send SMS
    $result = send_sms(
        $member['phone'], 
        $message, 
        null, // Use default sender
        $template_data,
        null  // Use default provider
    );
    
    // Log to SMS logs table with birthday type
    log_birthday_sms($member, $message, $template_data, $result);
    
    return $result;
}

/**
 * Log birthday SMS to database
 */
function log_birthday_sms($member, $message, $template_data, $result) {
    global $conn;
    
    // Process the message template
    $processed_message = process_template($message, $template_data);
    
    // Get SMS config for provider info
    $sms_config = get_sms_config();
    $provider = $sms_config ? $sms_config['default_provider'] : 'unknown';
    
    $status = (isset($result['status']) && $result['status'] === 'success') ? 'success' : 'fail';
    $response = json_encode($result, JSON_PRETTY_PRINT);
    
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

/**
 * Main function to process birthday SMS
 */
function process_birthday_sms() {
    log_birthday_activity("Starting birthday SMS processing...");
    
    try {
        // Get members with birthdays today
        $birthday_members = get_birthday_members();
        
        if (empty($birthday_members)) {
            log_birthday_activity("No members have birthdays today.");
            return;
        }
        
        log_birthday_activity("Found " . count($birthday_members) . " member(s) with birthdays today.");
        
        $success_count = 0;
        $error_count = 0;
        
        foreach ($birthday_members as $member) {
            log_birthday_activity("Processing birthday SMS for: {$member['full_name']} ({$member['phone']})");
            
            try {
                $result = send_birthday_sms($member);
                
                if (isset($result['status']) && $result['status'] === 'success') {
                    $success_count++;
                    log_birthday_activity("✓ Birthday SMS sent successfully to {$member['full_name']}", 'success');
                } else {
                    $error_count++;
                    $error_msg = isset($result['message']) ? $result['message'] : 'Unknown error';
                    log_birthday_activity("✗ Failed to send birthday SMS to {$member['full_name']}: $error_msg", 'error');
                }
                
                // Small delay between SMS to avoid rate limiting
                sleep(1);
                
            } catch (Exception $e) {
                $error_count++;
                log_birthday_activity("✗ Exception sending birthday SMS to {$member['full_name']}: " . $e->getMessage(), 'error');
            }
        }
        
        log_birthday_activity("Birthday SMS processing completed. Success: $success_count, Errors: $error_count");
        
        // Return summary for web requests
        if (isset($_GET['key'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'completed',
                'total_members' => count($birthday_members),
                'success_count' => $success_count,
                'error_count' => $error_count,
                'members' => array_map(function($m) { 
                    return ['name' => $m['full_name'], 'phone' => $m['phone']]; 
                }, $birthday_members)
            ], JSON_PRETTY_PRINT);
        }
        
    } catch (Exception $e) {
        log_birthday_activity("Fatal error in birthday SMS processing: " . $e->getMessage(), 'error');
        
        if (isset($_GET['key'])) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}

// Check if sms_logs table has the 'type' column, if not add it
function ensure_sms_logs_type_column() {
    global $conn;
    
    try {
        // Check if 'type' column exists
        $result = $conn->query("SHOW COLUMNS FROM sms_logs LIKE 'type'");
        
        if ($result->num_rows == 0) {
            // Add the 'type' column
            $conn->query("ALTER TABLE sms_logs ADD COLUMN type VARCHAR(50) DEFAULT 'general' AFTER template_name");
            log_birthday_activity("Added 'type' column to sms_logs table");
        }
    } catch (Exception $e) {
        log_birthday_activity("Error checking/adding type column: " . $e->getMessage(), 'error');
    }
}

// Run the script
if (php_sapi_name() === 'cli' || isset($_GET['key'])) {
    // Ensure database table is ready
    ensure_sms_logs_type_column();
    
    // Process birthday SMS
    process_birthday_sms();
} else {
    // Prevent direct web access without key
    http_response_code(403);
    echo "Access denied. This script requires authentication.";
}
?>
