<?php
/**
 * AJAX endpoint for retrieving members for bulk enrollment
 * Used by the Hikvision enrollment UI
 */
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check if user has permission
if (!has_permission('manage_hikvision_enrollment')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// Get parameters
$search = $_GET['search'] ?? '';
$device_id = isset($_GET['device_id']) ? intval($_GET['device_id']) : 0;

// Prepare response
$response = [
    'success' => true,
    'members' => []
];

// Get members that are not already enrolled with this device
$query = "
    SELECT m.id, m.firstname, m.lastname, m.phone
    FROM members m
    WHERE m.status = 'active'
";

// Add search condition if provided
if (!empty($search)) {
    $search_term = '%' . $search . '%';
    $query .= " AND (m.firstname LIKE ? OR m.lastname LIKE ? OR m.phone LIKE ?)";
}

// Exclude members already enrolled with this device if device_id is provided
if ($device_id > 0) {
    $query .= " AND m.id NOT IN (
        SELECT member_id FROM member_hikvision_data WHERE device_id = ? AND hikvision_user_id IS NOT NULL
    )";
}

$query .= " ORDER BY m.lastname, m.firstname LIMIT 100";

// Prepare and execute statement
if (!empty($search) && $device_id > 0) {
    $stmt = $conn->prepare($query);
    $search_term = '%' . $search . '%';
    $stmt->bind_param('sssi', $search_term, $search_term, $search_term, $device_id);
} elseif (!empty($search)) {
    $stmt = $conn->prepare($query);
    $search_term = '%' . $search . '%';
    $stmt->bind_param('sss', $search_term, $search_term, $search_term);
} elseif ($device_id > 0) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $device_id);
} else {
    $stmt = $conn->prepare($query);
}

$stmt->execute();
$result = $stmt->get_result();

// Build response
while ($member = $result->fetch_assoc()) {
    $response['members'][] = [
        'id' => $member['id'],
        'name' => $member['firstname'] . ' ' . $member['lastname'],
        'phone' => $member['phone']
    ];
}

echo json_encode($response);
?>
