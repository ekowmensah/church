<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/role_based_filter.php';
require_once __DIR__.'/../helpers/bible_class_capacity.php';

header('Content-Type: text/html; charset=utf-8');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo '<option value="">-- Login Required --</option>';
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_get_classes_by_church')) {
    http_response_code(403);
    echo '<option value="">-- Permission Denied --</option>';
    exit;
}

$church_id = intval($_GET['church_id'] ?? 0);
if (!$church_id) {
    echo '<option value="">-- Select Class --</option>';
    exit;
}

$class_id = intval($_GET['class_id'] ?? 0);
$capacity_rules_available = bible_class_rules_table_exists($conn);

$rules_select_max = $capacity_rules_available ? 'COALESCE(bcr.max_members, 25)' : '25';
$rules_select_enforce = $capacity_rules_available ? 'COALESCE(bcr.enforce_limit, 1)' : '1';
$rules_join = $capacity_rules_available ? 'LEFT JOIN bible_class_rules bcr ON bcr.class_id = bc.id' : '';

// Check if user is a class leader
$class_leader_class_ids = get_user_class_ids();

// Use prepared statement to prevent SQL injection
// FILTER: Class leaders only see their assigned classes
if ($class_leader_class_ids !== null) {
    // Class leader: only show their assigned classes
    $placeholders = implode(',', array_fill(0, count($class_leader_class_ids), '?'));
    $stmt = $conn->prepare("
        SELECT
            bc.id,
            bc.name,
            {$rules_select_max} AS max_members,
            {$rules_select_enforce} AS enforce_limit,
            COALESCE(bcm.active_member_count, 0) AS active_member_count
        FROM bible_classes bc
        {$rules_join}
        LEFT JOIN (
            SELECT class_id, COUNT(*) AS active_member_count
            FROM members
            WHERE status = 'active'
            GROUP BY class_id
        ) bcm ON bcm.class_id = bc.id
        WHERE bc.church_id = ? AND bc.id IN ($placeholders)
        ORDER BY bc.name ASC
    ");
    $bind_params = array_merge([$church_id], $class_leader_class_ids);
    $bind_types = 'i' . str_repeat('i', count($class_leader_class_ids));
    $stmt->bind_param($bind_types, ...$bind_params);
} else {
    // Not a class leader: show all classes for the church
    $stmt = $conn->prepare("
        SELECT
            bc.id,
            bc.name,
            {$rules_select_max} AS max_members,
            {$rules_select_enforce} AS enforce_limit,
            COALESCE(bcm.active_member_count, 0) AS active_member_count
        FROM bible_classes bc
        {$rules_join}
        LEFT JOIN (
            SELECT class_id, COUNT(*) AS active_member_count
            FROM members
            WHERE status = 'active'
            GROUP BY class_id
        ) bcm ON bcm.class_id = bc.id
        WHERE bc.church_id = ?
        ORDER BY bc.name ASC
    ");
    $stmt->bind_param('i', $church_id);
}

$stmt->execute();
$result = $stmt->get_result();

$options = '<option value="">-- Select Class --</option>';
while($row = $result->fetch_assoc()) {
    $selected = ($class_id && $row['id'] == $class_id) ? ' selected="selected"' : '';
    $active_member_count = (int) ($row['active_member_count'] ?? 0);
    $max_members = (int) ($row['max_members'] ?? 25);
    $enforce_limit = (int) ($row['enforce_limit'] ?? 1) === 1;
    $is_full = $enforce_limit && $active_member_count >= $max_members;
    $disabled = ($is_full && !$selected) ? ' disabled="disabled"' : '';
    $suffix = $enforce_limit
        ? " ({$active_member_count}/{$max_members}" . ($is_full ? ', FULL' : '') . ')'
        : " ({$active_member_count}, no limit)";
    $options .= '<option value="'.htmlspecialchars($row['id']).'"'.$selected.$disabled.'>'.htmlspecialchars($row['name'] . $suffix).'</option>';
}
$stmt->close();

echo $options;
