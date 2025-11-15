<?php
/**
 * AJAX endpoint for payment analysis management
 * Handles saving, retrieving, and managing payment analyses
 */

session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Set JSON header
header('Content-Type: application/json');

// Authentication check
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Permission check
if (!has_permission('payment_statistics')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$current_user_id = $_SESSION['user_id'] ?? 0;
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

try {
    switch ($action) {
        case 'save':
            saveAnalysis($conn, $current_user_id);
            break;
        case 'get':
            getAnalyses($conn, $current_user_id, $is_super_admin);
            break;
        case 'delete':
            deleteAnalysis($conn, $current_user_id, $is_super_admin);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Save payment analysis
 */
function saveAnalysis($conn, $user_id) {
    $date = $_POST['date'] ?? date('Y-m-d');
    $payment_mode = $_POST['payment_mode'] ?? 'cash';
    
    // Validate date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }
    
    // Validate payment mode
    if (!in_array($payment_mode, ['cash', 'cheque'])) {
        throw new Exception('Invalid payment mode');
    }
    
    if ($payment_mode === 'cash') {
        // Cash denomination analysis
        $denominations = $_POST['denom'] ?? [];
        $denomination_total = 0;
        
        // Calculate total
        $denom_config = [
            '200 Note' => 200, '100 Note' => 100, '50 Note' => 50,
            '20 Note' => 20, '10 Note' => 10, '5 Note' => 5,
            '2 Note' => 2, '1 Note' => 1, '2 Coin' => 2,
            '1 Coin' => 1, '0.50p' => 0.5, '0.20p' => 0.2, '0.10p' => 0.1
        ];
        
        foreach ($denominations as $label => $qty) {
            if (isset($denom_config[$label])) {
                $denomination_total += intval($qty) * $denom_config[$label];
            }
        }
        
        // Save to database
        $stmt = $conn->prepare("
            INSERT INTO payment_analyses 
            (analysis_date, payment_mode, created_by, denomination_data, denomination_total, status)
            VALUES (?, ?, ?, ?, ?, 'submitted')
            ON DUPLICATE KEY UPDATE
            denomination_data = VALUES(denomination_data),
            denomination_total = VALUES(denomination_total),
            updated_at = CURRENT_TIMESTAMP
        ");
        
        $denom_json = json_encode($denominations);
        $stmt->bind_param('ssisi', $date, $payment_mode, $user_id, $denom_json, $denomination_total);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Cash analysis saved successfully',
            'total' => $denomination_total
        ]);
        
    } else if ($payment_mode === 'cheque') {
        // Cheque analysis
        $cheque_count = intval($_POST['cheque_count'] ?? 0);
        $cheque_total = floatval($_POST['cheque_total'] ?? 0);
        $cheque_details = trim($_POST['cheque_details'] ?? '');
        
        // Save to database
        $stmt = $conn->prepare("
            INSERT INTO payment_analyses 
            (analysis_date, payment_mode, created_by, cheque_count, cheque_total, cheque_details, status)
            VALUES (?, ?, ?, ?, ?, ?, 'submitted')
            ON DUPLICATE KEY UPDATE
            cheque_count = VALUES(cheque_count),
            cheque_total = VALUES(cheque_total),
            cheque_details = VALUES(cheque_details),
            updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->bind_param('ssiids', $date, $payment_mode, $user_id, $cheque_count, $cheque_total, $cheque_details);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Cheque analysis saved successfully',
            'count' => $cheque_count,
            'total' => $cheque_total
        ]);
    }
}

/**
 * Get payment analyses
 */
function getAnalyses($conn, $user_id, $is_super_admin) {
    $date = $_GET['date'] ?? date('Y-m-d');
    
    // Validate date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }
    
    // Build query based on user role
    if ($is_super_admin) {
        // Super admin sees all analyses
        $sql = "
            SELECT pa.*, u.username, u.full_name
            FROM payment_analyses pa
            LEFT JOIN users u ON pa.created_by = u.id
            WHERE pa.analysis_date = ?
            ORDER BY pa.payment_mode, pa.created_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $date);
    } else {
        // Regular users see only their own
        $sql = "
            SELECT pa.*, u.username, u.full_name
            FROM payment_analyses pa
            LEFT JOIN users u ON pa.created_by = u.id
            WHERE pa.analysis_date = ? AND pa.created_by = ?
            ORDER BY pa.payment_mode, pa.created_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $date, $user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $analyses = [];
    
    while ($row = $result->fetch_assoc()) {
        // Decode JSON denomination data
        if ($row['denomination_data']) {
            $row['denomination_data'] = json_decode($row['denomination_data'], true);
        }
        $analyses[] = $row;
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'analyses' => $analyses,
        'count' => count($analyses)
    ]);
}

/**
 * Delete payment analysis
 */
function deleteAnalysis($conn, $user_id, $is_super_admin) {
    $analysis_id = intval($_POST['id'] ?? 0);
    
    if (!$analysis_id) {
        throw new Exception('Invalid analysis ID');
    }
    
    // Check ownership or admin status
    if ($is_super_admin) {
        $stmt = $conn->prepare("DELETE FROM payment_analyses WHERE id = ?");
        $stmt->bind_param('i', $analysis_id);
    } else {
        $stmt = $conn->prepare("DELETE FROM payment_analyses WHERE id = ? AND created_by = ?");
        $stmt->bind_param('ii', $analysis_id, $user_id);
    }
    
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    if ($affected > 0) {
        echo json_encode(['success' => true, 'message' => 'Analysis deleted successfully']);
    } else {
        throw new Exception('Analysis not found or you do not have permission to delete it');
    }
}
