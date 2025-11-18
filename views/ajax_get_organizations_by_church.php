<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/role_based_filter.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_get_organizations_by_church')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$church_id = intval($_GET['church_id'] ?? 0);
if (!$church_id) exit;
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// Check if user is an organizational leader
$org_leader_org_ids = get_user_organization_ids();

// Build query with role-based filtering
if ($org_leader_org_ids !== null) {
    // Org leader: only show their assigned organizations
    $placeholders = implode(',', array_fill(0, count($org_leader_org_ids), '?'));
    $sql = "SELECT id, name FROM organizations WHERE church_id = ? AND id IN ($placeholders)";
    if ($search !== '') {
        $sql .= " AND name LIKE ?";
    }
    $sql .= " ORDER BY name ASC";
    
    $stmt = $conn->prepare($sql);
    $bind_params = array_merge([$church_id], $org_leader_org_ids);
    $bind_types = 'i' . str_repeat('i', count($org_leader_org_ids));
    
    if ($search !== '') {
        $bind_params[] = '%' . $search . '%';
        $bind_types .= 's';
    }
    
    $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    // Not an org leader: show all organizations for the church
    $sql = "SELECT id, name FROM organizations WHERE church_id = ?";
    if ($search !== '') {
        $sql .= " AND name LIKE ?";
    }
    $sql .= " ORDER BY name ASC";
    
    $stmt = $conn->prepare($sql);
    if ($search !== '') {
        $stmt->bind_param('is', $church_id, '%' . $search . '%');
    } else {
        $stmt->bind_param('i', $church_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
}

$results = [];
while($row = $res->fetch_assoc()) {
    $results[] = [
        'id' => $row['id'],
        'text' => $row['name']
    ];
}
echo json_encode(['results' => $results]);
