<?php
/**
 * AJAX endpoint for searching members
 * Used by Select2 in the Hikvision enrollment UI
 */
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!is_logged_in()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Check if user has permission
if (!has_permission('manage_hikvision_enrollment')) {
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

// Get search term
$search = $_GET['q'] ?? '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 30;
$offset = ($page - 1) * $limit;

// Prepare response
$response = [
    'items' => [],
    'total_count' => 0
];

if (strlen($search) >= 2) {
    // Search for members
    $search_term = '%' . $search . '%';
    
    // Count total results
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total
        FROM members
        WHERE (firstname LIKE ? OR lastname LIKE ? OR phone LIKE ?)
        AND status = 'active'
    ");
    $stmt->bind_param('sss', $search_term, $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $response['total_count'] = $row['total'];
    
    // Get paginated results
    $stmt = $conn->prepare("
        SELECT id, firstname, lastname, phone
        FROM members
        WHERE (firstname LIKE ? OR lastname LIKE ? OR phone LIKE ?)
        AND status = 'active'
        ORDER BY lastname, firstname
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param('sssii', $search_term, $search_term, $search_term, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($member = $result->fetch_assoc()) {
        $response['items'][] = [
            'id' => $member['id'],
            'text' => $member['firstname'] . ' ' . $member['lastname'],
            'phone' => $member['phone']
        ];
    }
}

echo json_encode($response);
?>
