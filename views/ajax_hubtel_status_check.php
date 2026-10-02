<?php
header('Content-Type: application/json');
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/hubtel_status.php';
require_once __DIR__.'/../helpers/csrf.php';

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Check permissions
$is_super_admin = is_super_admin();

if (!$is_super_admin && !has_permission('manage_payments')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$csrfToken = is_array($input) ? ($input['csrf_token'] ?? null) : null;
if (!csrf_is_valid($csrfToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Your request expired. Refresh and retry.']);
    exit;
}
$action = $input['action'] ?? '';

switch ($action) {
    case 'check_single':
        $client_reference = trim($input['client_reference'] ?? '');
        if (!$client_reference) {
            echo json_encode(['success' => false, 'error' => 'Client reference is required']);
            exit;
        }
        
        $result = check_transaction_by_reference($conn, $client_reference, null, (int) ($_SESSION['user_id'] ?? 0));
        echo json_encode($result);
        break;
        
    case 'bulk_check':
        $limit = intval($input['limit'] ?? 25);
        $limit = max(1, min(100, $limit));
        
        $result = bulk_check_pending_payments($conn, $limit, (int) ($_SESSION['user_id'] ?? 0));
        echo json_encode(['success' => true, 'data' => $result]);
        break;
        
    case 'check_by_pos_id':
        $pos_sales_id = trim($input['pos_sales_id'] ?? '');
        if (!$pos_sales_id) {
            echo json_encode(['success' => false, 'error' => 'POS Sales ID is required']);
            exit;
        }
        
        $result = check_hubtel_transaction_status($pos_sales_id);
        echo json_encode($result);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
