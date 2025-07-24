<?php
require_once __DIR__.'/../config/config.php';
header('Content-Type: application/json');

$org_id = isset($_POST['org_id']) ? intval($_POST['org_id']) : 0;
$leader_user_id = isset($_POST['leader_user_id']) ? intval($_POST['leader_user_id']) : 0;
$church_id = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;

if (!$org_id || !$leader_user_id) {
    echo json_encode(['success' => false, 'error' => 'Missing data.']);
    exit;
}

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

// Validate church membership - ensure user belongs to the same church as the organization
if ($church_id) {
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

// Update organization with new leader (store user_id as leader_id)
$stmt = $conn->prepare('UPDATE organizations SET leader_id = ? WHERE id = ?');
$stmt->bind_param('ii', $leader_user_id, $org_id);
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error.']);
}
$stmt->close();
?>
