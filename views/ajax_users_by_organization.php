<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_users_by_organization')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Returns users with the Organizational Leader role (role_id=6) for a given church/organization
function output_error($msg) {
    echo json_encode(['error' => $msg]);
    exit;
}

if (!isset($conn) || !$conn) {
    output_error('No DB connection');
}

try {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
    $org_id = isset($_GET['org_id']) ? intval($_GET['org_id']) : 0;

    // role_id for Organizational Leader
    $org_leader_role_id = 6;

    // Build base query - filter by role, church membership, AND organization membership
    $sql = "SELECT u.id, u.name, u.email FROM users u
        INNER JOIN user_roles ur ON u.id = ur.user_id
        INNER JOIN members m ON u.member_id = m.id
        INNER JOIN member_organizations mo ON m.id = mo.member_id
        WHERE ur.role_id = ?";
    $params = [$org_leader_role_id];
    $types = 'i';

    // Filter by specific organization membership
    if ($org_id) {
        $sql .= " AND mo.organization_id = ?";
        $params[] = $org_id;
        $types .= 'i';
    }

    // Check if users table has church_id column and filter by church
    $has_church_id = false;
    $col_res = $conn->query("SHOW COLUMNS FROM users LIKE 'church_id'");
    if ($col_res && $col_res->num_rows > 0) {
        $has_church_id = true;
    }
    
    if ($church_id && $has_church_id) {
        $sql .= " AND u.church_id = ?";
        $params[] = $church_id;
        $types .= 'i';
    }

    // Add search filter if provided
    if ($q !== '') {
        $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
        $q_like = "%$q%";
        $params[] = $q_like;
        $params[] = $q_like;
        $types .= 'ss';
    }

    $sql .= " ORDER BY u.name ASC LIMIT 20";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Bind parameters based on count
    if (count($params) === 1) {
        $stmt->bind_param('i', $params[0]);
    } else {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => $row['id'],
            'text' => $row['name'] . ' (' . $row['email'] . ')',
            'email' => $row['email']
        ];
    }

    if (empty($items)) {
        $no_results = [
            'id' => 0,
            'text' => 'No eligible organizational leaders found who are members of this organization.',
            'email' => ''
        ];
        echo json_encode([
            'results' => [$no_results]
        ]);
    } else {
        echo json_encode(['results' => $items]);
    }

    $stmt->close();

} catch (Exception $e) {
    echo json_encode(['results' => [], 'error' => $e->getMessage()]);
}
?>
