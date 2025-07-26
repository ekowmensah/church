<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

header('Content-Type: application/json');

try {
    // Only allow logged-in users
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }

    // Check permissions
    $is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                      (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

    if (!$is_super_admin && !has_permission('view_member')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }

    // Only allow GET requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Validate member ID
    $member_id = filter_input(INPUT_GET, 'member_id', FILTER_VALIDATE_INT);

    if (!$member_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid member ID']);
        exit;
    }

    // Verify member exists
    $member_check = $conn->prepare("SELECT id, CONCAT(last_name, ' ', first_name, ' ', COALESCE(middle_name, '')) as full_name FROM members WHERE id = ?");
    $member_check->bind_param("i", $member_id);
    $member_check->execute();
    $member_result = $member_check->get_result();

    if ($member_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Member not found']);
        exit;
    }

    $member = $member_result->fetch_assoc();

    // Get adherent history for the member
    $history_query = "
        SELECT 
            a.id,
            a.reason,
            a.date_became_adherent,
            a.created_at,
            COALESCE(u.name, 'Unknown User') as marked_by_name
        FROM adherents a
        LEFT JOIN users u ON a.marked_by = u.id
        WHERE a.member_id = ?
        ORDER BY a.date_became_adherent DESC, a.created_at DESC
    ";

    $history_stmt = $conn->prepare($history_query);
    if (!$history_stmt) {
        throw new Exception("Failed to prepare history query: " . $conn->error);
    }
    
    $history_stmt->bind_param("i", $member_id);
    if (!$history_stmt->execute()) {
        throw new Exception("Failed to execute history query: " . $history_stmt->error);
    }
    
    $history_result = $history_stmt->get_result();

    $history = [];
    while ($row = $history_result->fetch_assoc()) {
        // Format dates for display
        $date_became_adherent = date('M j, Y', strtotime($row['date_became_adherent']));
        $created_at = date('M j, Y g:i A', strtotime($row['created_at']));
        
        // Handle case where user might be deleted
        $marked_by_name = $row['marked_by_name'] ?: 'Unknown User';

        $history[] = [
            'id' => $row['id'],
            'reason' => htmlspecialchars($row['reason']),
            'date_became_adherent' => $date_became_adherent,
            'date_became_adherent_raw' => $row['date_became_adherent'],
            'marked_by_name' => htmlspecialchars($marked_by_name),
            'created_at' => $created_at,
            'created_at_raw' => $row['created_at']
        ];
    }

    // Get current membership status for additional context
    $status_query = $conn->prepare("SELECT membership_status FROM members WHERE id = ?");
    if (!$status_query) {
        throw new Exception("Failed to prepare status query: " . $conn->error);
    }
    
    $status_query->bind_param("i", $member_id);
    if (!$status_query->execute()) {
        throw new Exception("Failed to execute status query: " . $status_query->error);
    }
    
    $status_result = $status_query->get_result();
    $current_status = $status_result->fetch_assoc()['membership_status'] ?? 'Unknown';

    echo json_encode([
        'success' => true,
        'member_name' => $member['full_name'],
        'current_status' => $current_status,
        'history' => $history,
        'total_records' => count($history)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    // Include actual error message for debugging
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to retrieve adherent history',
        'debug_error' => $e->getMessage(),
        'debug_file' => $e->getFile(),
        'debug_line' => $e->getLine()
    ]);
    
    // Log error for debugging
    error_log("ADHERENT_HISTORY_ERROR: " . $e->getMessage() . " - User: " . ($_SESSION['user_id'] ?? 'unknown') . " - Member: " . ($member_id ?? 'unknown'));
}
?>
