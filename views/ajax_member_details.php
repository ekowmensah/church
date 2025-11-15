<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('access_ajax_member_details')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$id = trim($_GET['id'] ?? '');
if (!$id) {
    echo json_encode(['success'=>false, 'msg'=>'No ID provided']);
    exit;
}

try {
    // Check database connection
    if (!$conn) {
        throw new Exception('Database connection not available');
    }
    
    // Get member details by ID, including phone and profession
    $stmt = $conn->prepare("SELECT id, crn, first_name, last_name, phone, profession, email FROM members WHERE id = ? LIMIT 1");
    if (!$stmt) throw new Exception('Member query prepare failed: ' . $conn->error);
    
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) throw new Exception('Member query execute failed: ' . $stmt->error);
    
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo json_encode($row);
        exit;
    }
    
    $stmt->close();
    echo json_encode(['success'=>false, 'msg'=>'Member not found']);
    
} catch (Exception $ex) {
    echo json_encode(['success'=>false, 'msg'=>'ERROR: '.$ex->getMessage()]);
}
