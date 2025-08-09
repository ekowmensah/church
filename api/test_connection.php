<?php
/**
 * Cloud Sync API - Test Connection Endpoint
 * 
 * Simple endpoint to test connectivity between local sync agent and cloud.
 * Returns basic system information and confirms authentication.
 */

require_once __DIR__.'/../config/config.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get JSON input for POST requests
$data = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];
}

// Authenticate sync agent
if (!authenticateSyncAgent()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication failed']);
    exit;
}

try {
    $result = handleTestConnection($data);
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
    
    // Validate credentials (should match other API endpoints)
    $valid_username = 'sync_agent';
    $valid_password = 'ZKTeco_Sync_2025_SecurePass!'; // Strong password for production
    
    return $username === $valid_username && $password === $valid_password;
}

/**
 * Handle test connection request
 */
function handleTestConnection($data) {
    global $conn;
    
    $agent_timestamp = $data['timestamp'] ?? null;
    $agent_version = $data['agent_version'] ?? 'unknown';
    
    // Get basic system information
    $system_info = [
        'server_time' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
        'database_connected' => $conn ? true : false,
        'api_version' => '1.0'
    ];
    
    // Test database connection
    if ($conn) {
        try {
            $result = $conn->query("SELECT COUNT(*) as device_count FROM zkteco_devices WHERE is_active = TRUE");
            if ($result) {
                $row = $result->fetch_assoc();
                $system_info['active_devices'] = (int)$row['device_count'];
            }
        } catch (Exception $e) {
            $system_info['database_error'] = $e->getMessage();
        }
    }
    
    // Calculate time difference if agent timestamp provided
    if ($agent_timestamp) {
        $agent_time = strtotime($agent_timestamp);
        $server_time = time();
        $time_diff = abs($server_time - $agent_time);
        
        $system_info['time_difference_seconds'] = $time_diff;
        $system_info['time_sync_ok'] = $time_diff < 300; // Within 5 minutes
    }
    
    // Log test connection
    logSyncActivity('test_connection', 1, 0, date('Y-m-d H:i:s'));
    
    return [
        'success' => true,
        'message' => 'Connection test successful',
        'agent_version' => $agent_version,
        'system_info' => $system_info,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Log sync activity for monitoring
 */
function logSyncActivity($activity_type, $processed, $errors, $timestamp) {
    global $conn;
    
    if (!$conn) return;
    
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
