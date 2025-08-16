<?php
/**
 * Test Hikvision Device Connection
 * 
 * This endpoint tests the connection to a Hikvision device.
 */

require_once '../config/config.php';
require_once '../helpers/permissions.php';
require_once '../includes/HikvisionService.php';

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

// Test connection
$hikvisionService = new HikvisionService();
$result = $hikvisionService->testDeviceConnection($device_id);

// Return the response
echo json_encode($result);
?>
