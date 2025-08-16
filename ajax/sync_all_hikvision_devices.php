<?php
/**
 * Sync All Hikvision Devices
 * 
 * This endpoint initiates synchronization with all active Hikvision devices.
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

$initiated_by = $_SESSION['username'] ?? 'system';

// Sync all devices using HikvisionService
$hikvisionService = new HikvisionService();
$results = $hikvisionService->syncAllDevices($initiated_by);

// Count successful syncs
$success_count = 0;
$total_count = count($results);

foreach ($results as $result) {
    if ($result['success']) {
        $success_count++;
    }
}

// Return result
if ($success_count > 0) {
    echo json_encode([
        'success' => true,
        'message' => "Sync initiated for $success_count out of $total_count devices",
        'results' => $results
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to initiate sync for any devices',
        'results' => $results
    ]);
}
?>
