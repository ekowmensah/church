<?php
// attendance_mark_ajax.php: Returns filtered member table rows for real-time search/filter in attendance_mark.php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    http_response_code(403);
    exit('Not authorized');
}

// Canonical permission check for Attendance Mark (AJAX)
if (!has_permission('mark_attendance')) {
    http_response_code(403);
    exit('Forbidden: You do not have permission to access this resource.');
}

$session_id = intval($_GET['id'] ?? 0);
if (!$session_id) {
    http_response_code(400);
    exit('No session ID');
}

$filter_class = $_GET['class_id'] ?? '';
$filter_org = $_GET['organization_id'] ?? '';
$search = trim($_GET['search'] ?? '');

// Query members as in attendance_mark.php
$sql = "SELECT m.id, m.first_name, m.last_name, m.middle_name, m.crn FROM members m ";
if ($filter_org) {
    $sql .= "LEFT JOIN member_organizations mo ON mo.member_id = m.id ";
}
$sql .= "WHERE 1 ";
$params = [];
$types = '';
if ($filter_class) {
    $sql .= "AND m.class_id = ? ";
    $params[] = $filter_class;
    $types .= 'i';
}
if ($filter_org) {
    $sql .= "AND mo.organization_id = ? ";
    $params[] = $filter_org;
    $types .= 'i';
}
if ($search !== '') {
    $sql .= " AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.middle_name LIKE ? OR m.crn LIKE ?) ";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}
$sql .= "ORDER BY m.last_name, m.first_name";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $members_result = $stmt->get_result();
} else {
    $members_result = $conn->query("SELECT id, first_name, last_name, middle_name, crn FROM members ORDER BY last_name, first_name");
}
$members = $members_result ? $members_result->fetch_all(MYSQLI_ASSOC) : [];

// Fetch previous attendance for this session and filtered members
$prev_attendance = [];
$member_ids = array_column($members, 'id');
if (count($member_ids) > 0) {
    $placeholders = implode(',', array_fill(0, count($member_ids), '?'));
    $sql_att = "SELECT member_id, status FROM attendance_records WHERE session_id = ? AND member_id IN ($placeholders)";
    $stmt = $conn->prepare($sql_att);
    $types_att = str_repeat('i', count($member_ids) + 1); // session_id + member_ids
    $bind_params = array_merge([$session_id], $member_ids);
    $refs = [];
    foreach ($bind_params as $k => $v) {
        $refs[$k] = &$bind_params[$k];
    }
    array_unshift($refs, $types_att);
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $prev_attendance[$row['member_id']] = $row['status'];
    }
}

// Output only <tbody> rows
foreach ($members as $member) {
    $checked = '';
    if (isset($prev_attendance[$member['id']])) {
        if (strtolower($prev_attendance[$member['id']]) === 'present') {
            $checked = 'checked';
        }
    }
    echo '<tr>';
    echo '<td>' . htmlspecialchars($member['crn'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($member['last_name'] . ', ' . $member['first_name'] . ' ' . $member['middle_name']) . '</td>';
    echo '<td>';
    echo '<div class="custom-control custom-switch">';
    echo '<input type="checkbox" class="custom-control-input" id="attend_' . $member['id'] . '" name="attendance[' . $member['id'] . ']" value="Present" ' . $checked . '>';
    echo '<label class="custom-control-label" for="attend_' . $member['id'] . '">Present</label>';
    echo '</div>';
    echo '</td>';
    echo '</tr>';
}
