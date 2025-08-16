<?php
/**
 * Sync Hikvision Device
 * 
 * This endpoint initiates a synchronization with a Hikvision device.
 */

require_once '../config/config.php';
require_once '../includes/HikvisionService.php';
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
$sync_type = isset($_POST['sync_type']) ? $_POST['sync_type'] : 'manual';
$initiated_by = $_SESSION['username'] ?? 'system';

// Sync device using HikvisionService
$hikvisionService = new HikvisionService();
$result = $hikvisionService->syncDeviceAttendance($device_id, $sync_type, $initiated_by);

// Return result
echo json_encode($result);
?>
