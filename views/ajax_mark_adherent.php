<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

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
    $reason = trim($_POST['reason'] ?? '');
    $date_became_adherent = $_POST['date_became_adherent'] ?? '';

    if (!$member_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid member ID']);
        exit;
    }

    if (empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'Reason is required']);
        exit;
    }

    if (empty($date_became_adherent) || !strtotime($date_became_adherent)) {
        echo json_encode(['success' => false, 'message' => 'Valid date is required']);
        exit;
    }

    // Validate date is not in the future
    if (strtotime($date_became_adherent) > time()) {
        echo json_encode(['success' => false, 'message' => 'Date cannot be in the future']);
        exit;
    }

    $current_user_id = $_SESSION['user_id'];

    // Start transaction
    $conn->begin_transaction();

    try {
        // First, verify the member exists and get current status
        $member_check = $conn->prepare("SELECT id, membership_status, CONCAT(last_name, ' ', first_name, ' ', middle_name) as full_name FROM members WHERE id = ? AND status = 'active'");
        $member_check->bind_param("i", $member_id);
        $member_check->execute();
        $member_result = $member_check->get_result();

        if ($member_result->num_rows === 0) {
            throw new Exception('Member not found or inactive');
        }

        $member = $member_result->fetch_assoc();

        // Update member's membership status to 'Adherent' (only if not already adherent)
        if ($member['membership_status'] !== 'Adherent') {
            $update_member = $conn->prepare("UPDATE members SET membership_status = 'Adherent' WHERE id = ?");
            $update_member->bind_param("i", $member_id);
            
            if (!$update_member->execute()) {
                throw new Exception('Failed to update member status');
            }
        }

        // Insert record into adherents table (allow multiple records)
        $insert_adherent = $conn->prepare("INSERT INTO adherents (member_id, reason, date_became_adherent, marked_by) VALUES (?, ?, ?, ?)");
        $insert_adherent->bind_param("issi", $member_id, $reason, $date_became_adherent, $current_user_id);
        
        if (!$insert_adherent->execute()) {
            throw new Exception('Failed to create adherent record');
        }

        // Commit transaction
        $conn->commit();

        // Log the action for audit purposes
        error_log("ADHERENT_MARKED: User {$current_user_id} marked member {$member_id} ({$member['full_name']}) as adherent on {$date_became_adherent}. Reason: {$reason}");

        echo json_encode([
            'success' => true, 
            'message' => 'Member successfully marked as adherent',
            'member_name' => $member['full_name'],
            'date' => $date_became_adherent
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
    error_log("ADHERENT_MARK_ERROR: " . $e->getMessage() . " - User: " . ($_SESSION['user_id'] ?? 'unknown') . " - Member: " . ($member_id ?? 'unknown'));
}
?>
