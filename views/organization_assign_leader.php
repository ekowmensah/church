<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

header('Content-Type: application/json');

// Authentication check
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Permission check
if (!has_permission('view_organization_list')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$org_id = isset($_POST['org_id']) ? intval($_POST['org_id']) : 0;
$leader_unique_id = isset($_POST['leader_user_id']) ? trim($_POST['leader_user_id']) : '';
$church_id = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;

if (!$org_id || !$leader_unique_id) {
    echo json_encode(['success' => false, 'error' => 'Missing data.']);
    exit;
}

// Parse unique_id to determine if it's a user or member
// Format: "user_123" or "member_456"
$leader_user_id = null;
$leader_member_id = null;

if (strpos($leader_unique_id, 'user_') === 0) {
    $leader_user_id = intval(substr($leader_unique_id, 5));
    
    // Validate user exists and has Organizational Leader role
    $role_check = $conn->prepare('SELECT u.id FROM users u INNER JOIN user_roles ur ON u.id = ur.user_id WHERE u.id = ? AND ur.role_id = 6');
    $role_check->bind_param('i', $leader_user_id);
    $role_check->execute();
    $role_check->store_result();
    if ($role_check->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Selected user is not an Organizational Leader.']);
        $role_check->close();
        exit;
    }
    $role_check->close();
    
    // Validate organization membership - ensure user is a member of this organization
    $member_check = $conn->prepare('
        SELECT u.id FROM users u 
        INNER JOIN members m ON u.member_id = m.id 
        INNER JOIN member_organizations mo ON m.id = mo.member_id 
        WHERE u.id = ? AND mo.organization_id = ?
    ');
    $member_check->bind_param('ii', $leader_user_id, $org_id);
    $member_check->execute();
    $member_check->store_result();
    if ($member_check->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Selected user is not a member of this organization.']);
        $member_check->close();
        exit;
    }
    $member_check->close();
} elseif (strpos($leader_unique_id, 'member_') === 0) {
    $leader_member_id = intval(substr($leader_unique_id, 7));
    
    // Validate member exists and belongs to this organization
    $member_check = $conn->prepare('
        SELECT m.id FROM members m
        INNER JOIN member_organizations mo ON m.id = mo.member_id
        WHERE m.id = ? AND mo.organization_id = ?
    ');
    $member_check->bind_param('ii', $leader_member_id, $org_id);
    $member_check->execute();
    $member_check->store_result();
    if ($member_check->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Selected member does not belong to this organization.']);
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

// Validate church membership - ensure user belongs to the same church as the organization (only if user_id exists)
if ($church_id && $leader_user_id) {
    // Check if users table has church_id column
    $col_res = $conn->query("SHOW COLUMNS FROM users LIKE 'church_id'");
    if ($col_res && $col_res->num_rows > 0) {
        $church_check = $conn->prepare('SELECT id FROM users WHERE id = ? AND church_id = ?');
        $church_check->bind_param('ii', $leader_user_id, $church_id);
        $church_check->execute();
        $church_check->store_result();
        if ($church_check->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'Selected user does not belong to the same church as this organization.']);
            $church_check->close();
            exit;
        }
        $church_check->close();
    }
}

// Validate organization exists and get its church_id for additional verification
$org_check = $conn->prepare('SELECT church_id FROM organizations WHERE id = ?');
$org_check->bind_param('i', $org_id);
$org_check->execute();
$org_result = $org_check->get_result();
if ($org_result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Organization not found.']);
    $org_check->close();
    exit;
}
$org_data = $org_result->fetch_assoc();
$org_check->close();

// Double-check church membership against organization's church_id
if ($org_data['church_id'] && $church_id && $org_data['church_id'] != $church_id) {
    echo json_encode(['success' => false, 'error' => 'Church ID mismatch.']);
    exit;
}

// Start transaction so legacy + new tables stay in sync
$conn->begin_transaction();

try {
    // 1. Deactivate existing active leaders for this organization
    $deactivate = $conn->prepare('UPDATE organization_leaders SET status = "inactive" WHERE organization_id = ? AND status = "active"');
    $deactivate->bind_param('i', $org_id);
    $deactivate->execute();
    $deactivate->close();

    // 2. Check if organization_leaders table has member_id column
    $has_member_id = false;
    $col_check = $conn->query("SHOW COLUMNS FROM organization_leaders LIKE 'member_id'");
    if ($col_check && $col_check->num_rows > 0) {
        $has_member_id = true;
    }

    // 3. Insert new leader assignment into organization_leaders table
    $assigned_by = $_SESSION['user_id'] ?? null;
    if ($has_member_id) {
        // New schema with member_id support
        $insert = $conn->prepare('INSERT INTO organization_leaders (organization_id, user_id, member_id, assigned_by, status, notes) VALUES (?, ?, ?, ?, "active", "Assigned via Organization List")');
        $insert->bind_param('iiii', $org_id, $leader_user_id, $leader_member_id, $assigned_by);
    } else {
        // Legacy schema - only user_id
        if (!$leader_user_id) {
            throw new Exception('Cannot assign member without user account to this organization. Member needs a user account first.');
        }
        $insert = $conn->prepare('INSERT INTO organization_leaders (organization_id, user_id, assigned_by, status, notes) VALUES (?, ?, ?, "active", "Assigned via Organization List")');
        $insert->bind_param('iii', $org_id, $leader_user_id, $assigned_by);
    }
    $insert->execute();
    $insert->close();

    // 4. Update organizations.leader_id for backward compatibility (use user_id if available, otherwise null)
    if ($leader_user_id) {
        $stmt = $conn->prepare('UPDATE organizations SET leader_id = ? WHERE id = ?');
        $stmt->bind_param('ii', $leader_user_id, $org_id);
    } else {
        // No user account - set to NULL for now
        $stmt = $conn->prepare('UPDATE organizations SET leader_id = NULL WHERE id = ?');
        $stmt->bind_param('i', $org_id);
    }
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    $message = $leader_user_id ? 'Organization leader assigned successfully' : 'Organization leader (member) assigned successfully';
    echo json_encode(['success' => true, 'message' => $message]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

?>
