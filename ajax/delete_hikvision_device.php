<?php
/**
 * Delete Hikvision Device
 * 
 * This endpoint deletes a Hikvision device if it has no attendance records.
 */

require_once '../config/config.php';
require_once '../helpers/permissions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to perform this action']);
    exit;
}

// Check if user has permission
if (!has_permission('manage_hikvision_devices')) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action']);
    exit;
}

// Check if device_id is provided
if (!isset($_POST['device_id']) || !is_numeric($_POST['device_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid device ID']);
    exit;
}

$device_id = intval($_POST['device_id']);

// Check if device has attendance records
$query = "SELECT COUNT(*) as count FROM hikvision_attendance_logs WHERE device_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $device_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if ($row['count'] > 0) {
    echo json_encode(['success' => false, 'message' => 'Cannot delete device with attendance records']);
    exit;
}

// Delete all related records with foreign key constraints to hikvision_devices

// 1. Delete related API keys
$query = "DELETE FROM hikvision_api_keys WHERE device_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $device_id);
$stmt->execute();

// 2. Delete related sync history records
$query = "DELETE FROM hikvision_sync_history WHERE device_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $device_id);
$stmt->execute();

// 3. Delete related attendance logs
$query = "DELETE FROM hikvision_attendance_logs WHERE device_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $device_id);
$stmt->execute();

// 4. Delete related member Hikvision data
$query = "DELETE FROM member_hikvision_data WHERE device_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $device_id);
$stmt->execute();

// 5. Delete related enrollments
$query = "DELETE FROM hikvision_enrollments WHERE device_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $device_id);
$stmt->execute();

// Now delete the device
$query = "DELETE FROM hikvision_devices WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $device_id);
$success = $stmt->execute();

if ($success) {
    
    echo json_encode(['success' => true, 'message' => 'Device deleted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete device']);
}
?>
