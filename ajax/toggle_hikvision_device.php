<?php
/**
 * Toggle Hikvision Device Status
 * 
 * This endpoint toggles the active status of a Hikvision device.
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

// Get current status
$query = "SELECT is_active FROM hikvision_devices WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $device_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Device not found']);
    exit;
}

$device = $result->fetch_assoc();
$new_status = $device['is_active'] ? 0 : 1;

// Update status
$query = "UPDATE hikvision_devices SET is_active = ? WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('ii', $new_status, $device_id);
$success = $stmt->execute();

if ($success) {
    echo json_encode([
        'success' => true, 
        'message' => 'Device status updated successfully',
        'new_status' => $new_status
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update device status']);
}
?>
