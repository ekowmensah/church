<?php
/**
 * Cloud Sync API - Device Status Endpoint
 * 
 * Receives device status updates from local sync agents.
 * Updates device connection status, version info, and statistics.
 */

require_once __DIR__.'/../config/config.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Authenticate sync agent
if (!authenticateSyncAgent()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication failed']);
    exit;
}

try {
    $result = updateDeviceStatuses($data);
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

/**
 * Authenticate sync agent using basic auth
 */
function authenticateSyncAgent() {
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? '';
    
    if (!$auth_header || !str_starts_with($auth_header, 'Basic ')) {
        return false;
    }
    
    $encoded_credentials = substr($auth_header, 6);
    $credentials = base64_decode($encoded_credentials);
    
    if (!$credentials) {
        return false;
    }
    
    list($username, $password) = explode(':', $credentials, 2);
    
    // Validate credentials (should match sync_attendance.php)
    $valid_username = 'sync_agent';
    $valid_password = 'ZKTeco_Sync_2025_SecurePass!'; // Strong password for production
    
    return $username === $valid_username && $password === $valid_password;
}

/**
 * Update device statuses from sync agent
 */
function updateDeviceStatuses($data) {
    global $conn;
    
    $devices = $data['devices'] ?? [];
    $timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');
    
    if (empty($devices)) {
        return [
            'success' => true,
            'message' => 'No device statuses to update',
            'updated' => 0
        ];
    }
    
    $updated = 0;
    $errors = [];
    
    // Prepare update statement
    $stmt = $conn->prepare("
        UPDATE zkteco_devices 
        SET 
            is_online = ?,
            last_test = ?,
            test_message = ?,
            firmware_version = ?,
            total_users = ?,
            total_records = ?
        WHERE id = ?
    ");
    
    foreach ($devices as $device_status) {
        try {
            $device_id = (int)$device_status['device_id'];
            $is_online = $device_status['is_online'] ? 1 : 0;
            $last_test = $device_status['last_test'] ?? $timestamp;
            $test_message = $device_status['test_message'] ?? '';
            $firmware_version = $device_status['version'] ?? null;
            $total_users = $device_status['users'] ?? null;
            $total_records = $device_status['records'] ?? null;
            
            $stmt->bind_param(
                'isssiii',
                $is_online,
                $last_test,
                $test_message,
                $firmware_version,
                $total_users,
                $total_records,
                $device_id
            );
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $updated++;
            } else {
                $errors[] = "Device {$device_id} not found or not updated";
            }
            
        } catch (Exception $e) {
            $errors[] = "Error updating device {$device_id}: " . $e->getMessage();
        }
    }
    
    // Log activity
    logSyncActivity('device_status_update', $updated, count($errors), $timestamp);
    
    return [
        'success' => true,
        'message' => 'Device statuses updated',
        'updated' => $updated,
        'total_devices' => count($devices),
        'errors' => $errors,
        'timestamp' => $timestamp
    ];
}

/**
 * Log sync activity for monitoring
 */
function logSyncActivity($activity_type, $processed, $errors, $timestamp) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO sync_activity_log 
        (activity_type, processed_records, error_count, sync_timestamp, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    
    if ($stmt) {
        $stmt->bind_param('siis', $activity_type, $processed, $errors, $timestamp);
        $stmt->execute();
    }
}
?>
