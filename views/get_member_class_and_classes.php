<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/bible_class_capacity.php';

// Authentication check
if (!is_logged_in()) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Permission check
if (!has_permission('view_member')) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

header('Content-Type: application/json');
$member_id = intval($_GET['member_id'] ?? 0);
if (!$member_id) {
    echo json_encode(['error'=>'No member_id']);
    exit;
}
// Get current class and church for member
$stmt = $conn->prepare('SELECT m.class_id, m.church_id, bc.name AS class_name FROM members m LEFT JOIN bible_classes bc ON m.class_id = bc.id WHERE m.id = ? LIMIT 1');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$res = $stmt->get_result();
if (!$row = $res->fetch_assoc()) {
    echo json_encode(['class_id'=>'','class_name'=>'','classes'=>[]]);
    exit;
}
$class_id = $row['class_id'];
$class_name = $row['class_name'];
$church_id = $row['church_id'];
// If no church_id, return empty classes
if (!$church_id) {
    echo json_encode(['class_id'=>$class_id,'class_name'=>$class_name ?: '', 'classes'=>[]]);
    exit;
}

$capacity_rules_available = bible_class_rules_table_exists($conn);
$rules_select_max = $capacity_rules_available ? 'COALESCE(bcr.max_members, 25)' : '25';
$rules_select_enforce = $capacity_rules_available ? 'COALESCE(bcr.enforce_limit, 1)' : '1';
$rules_join = $capacity_rules_available ? 'LEFT JOIN bible_class_rules bcr ON bcr.class_id = bc.id' : '';

// Get all classes in this church with capacity metadata
$stmt2 = $conn->prepare("
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
$stmt2->bind_param('i', $church_id);
$stmt2->execute();
$res2 = $stmt2->get_result();
$classes = [];
while ($r = $res2->fetch_assoc()) {
    $active_count = (int) ($r['active_member_count'] ?? 0);
    $max_members = (int) ($r['max_members'] ?? 25);
    $enforce_limit = (int) ($r['enforce_limit'] ?? 1) === 1;
    $is_full = $enforce_limit && $active_count >= $max_members;
    $r['is_full'] = $is_full;
    $r['remaining_slots'] = $enforce_limit ? max(0, $max_members - $active_count) : null;
    $r['label'] = $enforce_limit
        ? $r['name'] . " ({$active_count}/{$max_members}" . ($is_full ? ', FULL' : '') . ')'
        : $r['name'] . " ({$active_count}, no limit)";
    $classes[] = $r;
}
echo json_encode([
    'class_id' => $class_id,
    'class_name' => $class_name ?: '',
    'classes' => $classes
]);
