<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Set header FIRST before any output
header('Content-Type: application/json');

// Authentication check
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Permission check
if (!has_permission('view_bibleclass_list')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
$leader_unique_id = isset($_POST['leader_user_id']) ? trim($_POST['leader_user_id']) : '';

if (!$class_id || !$leader_unique_id) {
    echo json_encode(['success' => false, 'error' => 'Missing data.']);
    exit;
}

// Parse unique_id to determine if it's a user or member
// Format: "user_123" or "member_456"
$leader_user_id = null;
$leader_member_id = null;

if (strpos($leader_unique_id, 'user_') === 0) {
    $leader_user_id = intval(substr($leader_unique_id, 5));
    
    // Validate user exists and has Class Leader role
    $role_check = $conn->prepare('SELECT u.id FROM users u INNER JOIN user_roles ur ON u.id = ur.user_id WHERE u.id = ? AND ur.role_id = 5');
    $role_check->bind_param('i', $leader_user_id);
    $role_check->execute();
    $role_check->store_result();
    if ($role_check->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Selected user is not a Class Leader.']);
        $role_check->close();
        exit;
    }
    $role_check->close();
} elseif (strpos($leader_unique_id, 'member_') === 0) {
    $leader_member_id = intval(substr($leader_unique_id, 7));
    
    // Validate member exists and belongs to this Bible class
    $member_check = $conn->prepare('SELECT id FROM members WHERE id = ? AND class_id = ?');
    $member_check->bind_param('ii', $leader_member_id, $class_id);
    $member_check->execute();
    $member_check->store_result();
    if ($member_check->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Selected member does not belong to this Bible class.']);
        $member_check->close();
        exit;
    }
    $member_check->close();
    
    // Check if member has a user account, if so use that
    $user_check = $conn->prepare('SELECT id FROM users WHERE member_id = ?');
    $user_check->bind_param('i', $leader_member_id);
    $user_check->execute();
    $user_result = $user_check->get_result();
    if ($user_result->num_rows > 0) {
        $user_row = $user_result->fetch_assoc();
        $leader_user_id = $user_row['id'];
    }
    $user_check->close();
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid leader ID format.']);
    exit;
}

// Get current user for audit trail
$assigned_by = $_SESSION['user_id'] ?? null;

// Start transaction
$conn->begin_transaction();

try {
    // 1. Deactivate any existing active leaders for this class
    $deactivate = $conn->prepare('UPDATE bible_class_leaders SET status = "inactive" WHERE class_id = ? AND status = "active"');
    $deactivate->bind_param('i', $class_id);
    $deactivate->execute();
    $deactivate->close();
    
    // 2. Check if bible_class_leaders table has member_id column
    $has_member_id = false;
    $col_check = $conn->query("SHOW COLUMNS FROM bible_class_leaders LIKE 'member_id'");
    if ($col_check && $col_check->num_rows > 0) {
        $has_member_id = true;
    }
    
    // 3. Insert new leader assignment into bible_class_leaders table
    if ($has_member_id) {
        // New schema with member_id support
        $insert = $conn->prepare('INSERT INTO bible_class_leaders (class_id, user_id, member_id, assigned_by, status, notes) VALUES (?, ?, ?, ?, "active", "Assigned via Bible Class List")');
        $insert->bind_param('iiii', $class_id, $leader_user_id, $leader_member_id, $assigned_by);
    } else {
        // Legacy schema - only user_id
        if (!$leader_user_id) {
            throw new Exception('Cannot assign member without user account to this Bible class. Member needs a user account first.');
        }
        $insert = $conn->prepare('INSERT INTO bible_class_leaders (class_id, user_id, assigned_by, status, notes) VALUES (?, ?, ?, "active", "Assigned via Bible Class List")');
        $insert->bind_param('iii', $class_id, $leader_user_id, $assigned_by);
    }
    $insert->execute();
    $insert->close();
    
    // 4. Update bible_classes.leader_id for backward compatibility (use user_id if available, otherwise null)
    if ($leader_user_id) {
        $update = $conn->prepare('UPDATE bible_classes SET leader_id = ? WHERE id = ?');
        $update->bind_param('ii', $leader_user_id, $class_id);
    } else {
        // No user account - set to NULL for now (could be enhanced to store member_id in future)
        $update = $conn->prepare('UPDATE bible_classes SET leader_id = NULL WHERE id = ?');
        $update->bind_param('i', $class_id);
    }
    $update->execute();
    $update->close();
    
    // Commit transaction
    $conn->commit();
    
    $message = $leader_user_id ? 'Class leader assigned successfully' : 'Class leader (member) assigned successfully';
    echo json_encode(['success' => true, 'message' => $message]);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
