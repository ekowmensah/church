<?php
/**
 * Hikvision Attendance Sync API Endpoint
 * 
 * This endpoint receives attendance data from the Hikvision local sync agent
 * and processes it for the church management system.
 * 
 * Authentication: API Key (X-API-Key header)
 * Method: POST
 * Content-Type: application/json
 */

// Set content type to JSON
header('Content-Type: application/json');

// Include required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/db_connect.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/logger.php';

// Initialize logger
$logger = new Logger('hikvision_api');

// Log request
$logger->log('Received Hikvision sync request');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    $logger->log('Invalid request method: ' . $_SERVER['REQUEST_METHOD'], Logger::ERROR);
    exit;
}

// Check API key
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($api_key)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'API key required']);
    $logger->log('Missing API key', Logger::ERROR);
    exit;
}

// Validate API key
$stmt = $conn->prepare("
    SELECT device_id FROM hikvision_api_keys 
    WHERE api_key = ? AND expires_at > NOW() AND is_active = 1
");
$stmt->bind_param('s', $api_key);
$stmt->execute();
$result = $stmt->get_result();
$device = $result->fetch_assoc();

if (!$device) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired API key']);
    $logger->log('Invalid API key: ' . $api_key, Logger::ERROR);
    exit;
}

$device_id = $device['device_id'];

// Get request body
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    $logger->log('Invalid JSON data received', Logger::ERROR);
    exit;
}

// Validate data structure
if (!isset($data['device']) || !isset($data['records'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data format']);
    $logger->log('Invalid data format: missing device or records', Logger::ERROR);
    exit;
}

// Extract records
$records = $data['records'];
$recordCount = count($records);
$logger->log("Processing {$recordCount} attendance records from device {$device_id}");

// Start sync history record
$stmt = $conn->prepare("
    INSERT INTO hikvision_sync_history 
    (device_id, sync_type, start_time, status)
    VALUES (?, 'automatic', NOW(), 'in_progress')
");
$stmt->bind_param('i', $device_id);
$stmt->execute();
$sync_history_id = $stmt->insert_id;

// Process records
$processed = 0;
$errors = 0;

foreach ($records as $record) {
    try {
        // Extract data from Hikvision format
        $hikvision_user_id = $record['employeeNoString'] ?? '';
        $timestamp = $record['time'] ?? '';
        $event_type = $record['eventType'] ?? '';
        $verification_mode = $record['cardReaderKind'] ?? '';
        
        if (empty($hikvision_user_id) || empty($timestamp)) {
            $logger->log("Skipping record with missing data: user_id={$hikvision_user_id}, timestamp={$timestamp}", Logger::WARNING);
            $errors++;
            continue;
        }
        
        // Insert raw log
        $stmt = $conn->prepare("
            INSERT INTO hikvision_attendance_logs 
            (device_id, hikvision_user_id, timestamp, event_type, verification_mode)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('issss', $device_id, $hikvision_user_id, $timestamp, $event_type, $verification_mode);
        $stmt->execute();
        $log_id = $stmt->insert_id;
        
        // Find the member associated with this Hikvision user ID
        $stmt = $conn->prepare("
            SELECT member_id FROM member_hikvision_data
            WHERE device_id = ? AND hikvision_user_id = ?
        ");
        $stmt->bind_param('is', $device_id, $hikvision_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $member = $result->fetch_assoc();
        
        if (!$member) {
            $logger->log("No member found for Hikvision user ID: {$hikvision_user_id}", Logger::WARNING);
            continue;
        }
        
        $member_id = $member['member_id'];
        $attendance_date = date('Y-m-d', strtotime($timestamp));
        $attendance_time = date('H:i:s', strtotime($timestamp));
        
        // Check if attendance already exists for this member on this date
        $stmt = $conn->prepare("
            SELECT id FROM attendance 
            WHERE member_id = ? AND attendance_date = ?
        ");
        $stmt->bind_param('is', $member_id, $attendance_date);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        
        if ($existing) {
            // Update existing attendance record
            $stmt = $conn->prepare("
                UPDATE attendance 
                SET time_in = ?, verification_mode = ?, device_id = ?, updated_at = NOW()
                WHERE id = ?
            ");
            // Determine verification mode based on device data
            $verification = $record['cardReaderKind'] === 'fingerprint' ? 'Fingerprint' : 'Face Recognition';
            $stmt->bind_param('ssii', $attendance_time, $verification, $device_id, $existing['id']);
            $stmt->execute();
            $logger->log("Updated attendance for member {$member_id} on {$attendance_date}");
        } else {
            // Create new attendance record
            $stmt = $conn->prepare("
                INSERT INTO attendance 
                (member_id, attendance_date, time_in, verification_mode, device_id, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            // Determine verification mode based on device data
            $verification = $record['cardReaderKind'] === 'fingerprint' ? 'Fingerprint' : 'Face Recognition';
            $stmt->bind_param('isssi', $member_id, $attendance_date, $attendance_time, $verification, $device_id);
            $stmt->execute();
            $logger->log("Created new attendance for member {$member_id} on {$attendance_date}");
        }
        
        // Mark log as processed
        $stmt = $conn->prepare("
            UPDATE hikvision_attendance_logs
            SET processed = TRUE
            WHERE id = ?
        ");
        $stmt->bind_param('i', $log_id);
        $stmt->execute();
        
        $processed++;
    } catch (Exception $e) {
        $logger->log("Error processing record: " . $e->getMessage(), Logger::ERROR);
        $errors++;
    }
}

// Update sync history
$status = ($errors == 0) ? 'completed' : 'completed_with_errors';
$error_message = ($errors > 0) ? "{$errors} records failed processing" : null;

$stmt = $conn->prepare("
    UPDATE hikvision_sync_history
    SET status = ?, records_synced = ?, records_processed = ?, 
        error_message = ?, end_time = NOW()
    WHERE id = ?
");
$stmt->bind_param('siisi', $status, $recordCount, $processed, $error_message, $sync_history_id);
$stmt->execute();

// Update device last sync time
$stmt = $conn->prepare("
    UPDATE hikvision_devices
    SET last_sync = NOW(), total_records = total_records + ?
    WHERE id = ?
");
$stmt->bind_param('ii', $processed, $device_id);
$stmt->execute();

// Return response
echo json_encode([
    'success' => true,
    'message' => "Successfully processed {$processed} of {$recordCount} records",
    'processed' => $processed,
    'errors' => $errors,
    'sync_id' => $sync_history_id
]);

$logger->log("Hikvision sync completed: {$processed} processed, {$errors} errors");
?>
