<?php
/**
 * AJAX Member Search Endpoint
 * Used for searching members in various forms and modals
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

header('Content-Type: application/json');

// Check authentication
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
// Permission check
if (!has_permission('view_dashboard')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}


$query = $_GET['q'] ?? '';
$limit = intval($_GET['limit'] ?? 10);

if (strlen($query) < 2) {
    echo json_encode(['success' => false, 'error' => 'Query too short']);
    exit;
}

try {
    // Search members by name, CRN, or phone
    $search_param = "%$query%";
    
    $stmt = $conn->prepare("
        SELECT id, 
               CONCAT(first_name, ' ', last_name) as full_name,
               crn, 
               phone,
               church_id
        FROM members 
        WHERE status = 'active' 
        AND (
            CONCAT(first_name, ' ', last_name) LIKE ? 
            OR crn LIKE ? 
            OR phone LIKE ?
        )
        ORDER BY 
            CASE 
                WHEN CONCAT(first_name, ' ', last_name) LIKE ? THEN 1
                WHEN crn LIKE ? THEN 2
                ELSE 3
            END,
            first_name, last_name
        LIMIT ?
    ");
    
    $stmt->bind_param('sssssi', $search_param, $search_param, $search_param, $search_param, $search_param, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'members' => $members,
        'count' => count($members)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Search failed']);
}
?>
