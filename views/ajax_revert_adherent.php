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

    if (!$is_super_admin && !has_permission('edit_member')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }

    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Validate required fields
    $member_id = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);

    if (!$member_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid member ID']);
        exit;
    }

    $current_user_id = $_SESSION['user_id'];

    // Start transaction
    $conn->begin_transaction();

    try {
        // First, verify the member exists and is currently an adherent
        $member_check = $conn->prepare("SELECT id, membership_status, CONCAT(last_name, ' ', first_name, ' ', middle_name) as full_name FROM members WHERE id = ? AND status = 'active'");
        $member_check->bind_param("i", $member_id);
        $member_check->execute();
        $member_result = $member_check->get_result();

        if ($member_result->num_rows === 0) {
            throw new Exception('Member not found or inactive');
        }

        $member = $member_result->fetch_assoc();

        // Check if member is currently an adherent
        if ($member['membership_status'] !== 'Adherent') {
            throw new Exception('Member is not currently marked as adherent');
        }

        // Update member's membership status back to 'Full Member'
        $update_member = $conn->prepare("UPDATE members SET membership_status = 'Full Member' WHERE id = ?");
        $update_member->bind_param("i", $member_id);
        
        if (!$update_member->execute()) {
            throw new Exception('Failed to update member status');
        }

        // Note: We don't delete adherent records for audit trail purposes
        // The adherent history remains intact for reference

        // Commit transaction
        $conn->commit();

        // Log the action for audit purposes
        error_log("ADHERENT_REVERTED: User {$current_user_id} reverted member {$member_id} ({$member['full_name']}) from adherent status back to Full Member");

        echo json_encode([
            'success' => true, 
            'message' => 'Member adherent status successfully reverted',
            'member_name' => $member['full_name'],
            'new_status' => 'Full Member'
        ]);

    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    
    // Log error for debugging
    error_log("ADHERENT_REVERT_ERROR: " . $e->getMessage() . " - User: " . ($_SESSION['user_id'] ?? 'unknown') . " - Member: " . ($member_id ?? 'unknown'));
}
?>
