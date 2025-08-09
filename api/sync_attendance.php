<?php
/**
 * Cloud Sync API - Attendance Data Endpoint
 * 
 * Receives attendance data from local sync agents and processes it into the database.
 * Handles authentication, validation, and data insertion.
 */

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

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

// Only allow POST requests for data submission
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

// Handle different actions
$action = $data['action'] ?? '';

try {
    switch ($action) {
        case 'sync_attendance':
            $result = handleAttendanceSync($data);
            break;
            
        case 'get_last_sync_timestamps':
            $result = getLastSyncTimestamps();
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
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
    // Check for Authorization header
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? '';
    
    if (!$auth_header || !str_starts_with($auth_header, 'Basic ')) {
        return false;
    }
    
    // Decode basic auth
    $encoded_credentials = substr($auth_header, 6);
    $credentials = base64_decode($encoded_credentials);
    
    if (!$credentials) {
        return false;
    }
    
    list($username, $password) = explode(':', $credentials, 2);
    
    // Validate credentials (you should store these securely)
    $valid_username = 'sync_agent';
    $valid_password = 'ZKTeco_Sync_2025_SecurePass!'; // Strong password for production
    
    return $username === $valid_username && $password === $valid_password;
}

/**
 * Handle attendance data sync from local agent
 * Compatible with existing ZKTecoService logic
 */
function handleAttendanceSync($data) {
    global $conn;
    
    $records = $data['records'] ?? [];
    $sync_timestamp = $data['sync_timestamp'] ?? date('Y-m-d H:i:s');
    $device_id = $data['device_id'] ?? null;
    $sync_type = $data['sync_type'] ?? 'remote_agent';
    
    if (empty($records)) {
        return [
            'success' => true,
            'message' => 'No records to process',
            'processed' => 0
        ];
    }
    
    // Start sync history record (compatible with existing system)
    $sync_history_id = startSyncHistory($device_id, $sync_type, 'sync_agent');
    
    $processed = 0;
    $synced = 0;
    $errors = [];
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        foreach ($records as $record) {
            try {
                // Validate required fields
                if (!isset($record['device_id'], $record['user_id'], $record['timestamp'])) {
                    $errors[] = "Missing required fields in record";
                    continue;
                }
                
                // Process using existing ZKTecoService logic
                if (processRawAttendanceLogCompat($record, $conn)) {
                    $synced++;
                }
                $processed++;
                
            } catch (Exception $e) {
                $errors[] = "Error processing record: " . $e->getMessage();
            }
        }
        
        // Update sync history (compatible with existing system)
        updateSyncHistory($sync_history_id, 'success', $synced, $processed, null, $conn);
        
        // Update device info
        if ($device_id) {
            updateDeviceInfo($device_id, null, null, null, $sync_timestamp, $conn);
        }
        
        // Commit transaction
        $conn->commit();
        
        return [
            'success' => true,
            'message' => 'Attendance data synced successfully',
            'processed' => $processed,
            'synced' => $synced,
            'total_records' => count($records),
            'errors' => $errors,
            'sync_timestamp' => $sync_timestamp,
            'sync_history_id' => $sync_history_id
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        updateSyncHistory($sync_history_id, 'failed', 0, 0, $e->getMessage(), $conn);
        throw $e;
    }
}

/**
 * Get last sync timestamps for all devices
 */
function getLastSyncTimestamps() {
    global $conn;
    
    $query = "
        SELECT id, device_name, last_sync 
        FROM zkteco_devices 
        WHERE is_active = TRUE 
        ORDER BY id
    ";
    
    $result = $conn->query($query);
    $timestamps = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $timestamps[$row['id']] = $row['last_sync'];
        }
    }
    
    return [
        'success' => true,
        'message' => 'Sync timestamps retrieved',
        'timestamps' => $timestamps
    ];
}

/**
 * Process raw attendance log compatible with existing ZKTecoService
 */
function processRawAttendanceLogCompat($record, $conn) {
    $device_id = (int)$record['device_id'];
    $user_id = $record['user_id'];
    $timestamp = $record['timestamp'];
    $verify_type = (int)($record['verify_type'] ?? 0);
    $in_out_mode = (int)($record['in_out_mode'] ?? 0);
    $raw_data = $record['raw_data'] ?? '';
    
    // Check if this record already exists in zkteco_raw_logs
    $stmt = $conn->prepare("
        SELECT id FROM zkteco_raw_logs 
        WHERE device_id = ? AND zk_user_id = ? AND timestamp = ?
    ");
    $stmt->bind_param('iss', $device_id, $user_id, $timestamp);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    
    if ($existing) {
        return false; // Record already exists
    }
    
    // Map verification type and in/out mode
    $verification_type = mapVerificationType($verify_type);
    $in_out_mode_mapped = mapInOutMode($in_out_mode);
    
    // Insert into zkteco_raw_logs (existing table)
    $stmt = $conn->prepare("
        INSERT INTO zkteco_raw_logs 
        (device_id, zk_user_id, timestamp, verification_type, in_out_mode, raw_data, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param('isssss', 
        $device_id, 
        $user_id, 
        $timestamp, 
        $verification_type, 
        $in_out_mode_mapped, 
        $raw_data
    );
    
    return $stmt->execute();
}

/**
 * Map ZKTeco verification type to readable format (compatible with existing)
 */
function mapVerificationType($verify_type) {
    switch ($verify_type) {
        case 1: return 'fingerprint';
        case 15: return 'face';
        case 5: return 'card';
        case 0: return 'password';
        default: return 'unknown';
    }
}

/**
 * Map ZKTeco in/out mode to readable format (compatible with existing)
 */
function mapInOutMode($in_out_mode) {
    switch ($in_out_mode) {
        case 0: return 'check_in';
        case 1: return 'check_out';
        case 2: return 'break_out';
        case 3: return 'break_in';
        case 4: return 'overtime_in';
        case 5: return 'overtime_out';
        default: return 'unknown';
    }
}

/**
 * Start sync history record (compatible with existing system)
 */
function startSyncHistory($device_id, $sync_type, $initiated_by) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO zkteco_sync_history 
        (device_id, sync_type, initiated_by, status, started_at) 
        VALUES (?, ?, ?, 'in_progress', NOW())
    ");
    $stmt->bind_param('iss', $device_id, $sync_type, $initiated_by);
    
    if ($stmt->execute()) {
        return $conn->insert_id;
    }
    
    return null;
}

/**
 * Update sync history record (compatible with existing system)
 */
function updateSyncHistory($sync_history_id, $status, $synced, $processed, $error_message, $conn) {
    $stmt = $conn->prepare("
        UPDATE zkteco_sync_history 
        SET status = ?, records_synced = ?, records_processed = ?, 
            error_message = ?, completed_at = NOW() 
        WHERE id = ?
    ");
    $stmt->bind_param('siisi', $status, $synced, $processed, $error_message, $sync_history_id);
    $stmt->execute();
}

/**
 * Update device information (compatible with existing system)
 */
function updateDeviceInfo($device_id, $version, $userCount, $recordCount, $sync_timestamp, $conn) {
    $stmt = $conn->prepare("
        UPDATE zkteco_devices 
        SET last_sync = ?, firmware_version = COALESCE(?, firmware_version), 
            total_users = COALESCE(?, total_users), 
            total_records = COALESCE(?, total_records)
        WHERE id = ?
    ");
    $stmt->bind_param('ssiii', $sync_timestamp, $version, $userCount, $recordCount, $device_id);
    $stmt->execute();
}
?>
