<?php
/**
 * Update Hikvision Device
 * 
 * This endpoint updates a Hikvision device's details.
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

// Check if required fields are provided
if (!isset($_POST['device_id']) || !is_numeric($_POST['device_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid device ID']);
    exit;
}

if (!isset($_POST['name']) || empty($_POST['name'])) {
    echo json_encode(['success' => false, 'message' => 'Device name is required']);
    exit;
}

if (!isset($_POST['ip_address']) || empty($_POST['ip_address'])) {
    echo json_encode(['success' => false, 'message' => 'IP address is required']);
    exit;
}

if (!isset($_POST['port']) || !is_numeric($_POST['port'])) {
    echo json_encode(['success' => false, 'message' => 'Valid port number is required']);
    exit;
}

if (!isset($_POST['username']) || empty($_POST['username'])) {
    echo json_encode(['success' => false, 'message' => 'Username is required']);
    exit;
}

// Get parameters
$device_id = intval($_POST['device_id']);

// Prepare data array for updateDevice
$data = [
    'name' => $_POST['name'],
    'ip_address' => $_POST['ip_address'],
    'port' => intval($_POST['port']),
    'username' => $_POST['username']
];

// Optional parameters
if (isset($_POST['password']) && !empty($_POST['password'])) {
    $data['password'] = $_POST['password'];
}

if (isset($_POST['location'])) {
    $data['location'] = $_POST['location'];
}

if (isset($_POST['church_id']) && is_numeric($_POST['church_id'])) {
    $data['church_id'] = intval($_POST['church_id']);
}

// Update device using HikvisionService
$hikvisionService = new HikvisionService();
$result = $hikvisionService->updateDevice($device_id, $data);

// Return result
echo json_encode($result);
?>
