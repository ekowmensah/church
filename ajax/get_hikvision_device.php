<?php
/**
 * Get Hikvision Device Details
 * 
 * This endpoint retrieves the details of a Hikvision device for editing.
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
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid device ID']);
    exit;
}

$device_id = intval($_GET['id']);

// Get device details
$query = "SELECT * FROM hikvision_devices WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $device_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Device not found']);
    exit;
}

$device = $result->fetch_assoc();

// Format the response
$response = [
    'success' => true,
    'device' => [
        'id' => $device['id'],
        'name' => $device['name'],
        'ip' => $device['ip_address'],
        'port' => $device['port'],
        'username' => $device['username'],
        'location' => $device['location'],
        'church_id' => $device['church_id'],
        'is_active' => $device['is_active']
    ]
];

// Return the response
echo json_encode($response);
?>
