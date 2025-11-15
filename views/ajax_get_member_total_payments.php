<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
// Permission check
if (!has_permission('view_dashboard')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}


// Set content type to JSON
header('Content-Type: application/json');

// Get member ID from request
$member_id = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;

if ($member_id <= 0) {
    echo json_encode(['error' => 'Invalid member ID']);
    exit;
}

try {
    // Query to get total payments for the member (exclude reversed payments)
    $sql = "SELECT SUM(amount) as total FROM payments 
            WHERE member_id = ? 
            AND reversal_approved_at IS NULL 
            AND amount IS NOT NULL";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $total = $row['total'] ? floatval($row['total']) : 0;
    
    echo json_encode([
        'success' => true,
        'total' => $total,
        'formatted' => number_format($total, 2)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage(),
        'total' => 0
    ]);
}
?>
