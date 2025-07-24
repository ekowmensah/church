<?php
if (session_status() === PHP_SESSION_NONE) session_start();
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
if (!$is_super_admin && !has_permission('access_ajax_members_by_church')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;

$is_class_leader = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 5);
$linked_member_id = isset($_SESSION['member_id']) ? intval($_SESSION['member_id']) : 0;
$class_leader_class_id = 0;
if ($is_class_leader && $linked_member_id) {
    // Get the class_id of the linked member
    $stmt = $conn->prepare('SELECT class_id FROM members WHERE id = ?');
    $stmt->bind_param('i', $linked_member_id);
    $stmt->execute();
    $stmt->bind_result($class_leader_class_id);
    $stmt->fetch();
    $stmt->close();
}
$sql = "SELECT m.id, m.first_name, m.last_name, m.middle_name, CONCAT(m.last_name, ' ', m.first_name, ' ', m.middle_name, ' (', m.crn, ')') as text, m.crn, c.name as class_name
        FROM members m
        LEFT JOIN bible_classes c ON m.class_id = c.id
        WHERE m.status = 'active'";
// Restrict to class for class leaders (CRN)
if ($is_class_leader && $class_leader_class_id) {
    $sql .= " AND m.class_id = ?";
    $params[] = $class_leader_class_id;
    $types .= 'i';
}

$params = [];
$types = '';
$gender = isset($_GET['gender']) ? strtolower(trim($_GET['gender'])) : '';
if ($church_id) {
    $sql .= " AND m.church_id = ?";
    $params[] = $church_id;
    $types .= 'i';
}
if (in_array($gender, ['male','female'])) {
    $sql .= " AND LOWER(m.gender) = ?";
    $params[] = strtolower($gender);
    $types .= 's';
}
if ($q !== '') {
    $sql .= " AND (m.last_name LIKE ? OR m.first_name LIKE ? OR m.middle_name LIKE ? OR m.crn LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
$sql .= " ORDER BY m.last_name, m.first_name, m.middle_name LIMIT 20";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$results = [];
while ($row = $res->fetch_assoc()) {
    $results[] = [
        'id' => $row['id'],
        'text' => $row['text'],
        'crn' => $row['crn'],
        'class' => $row['class_name'],
        'first_name' => isset($row['first_name']) ? $row['first_name'] : (isset($row['first']) ? $row['first'] : ''),
        'last_name' => isset($row['last_name']) ? $row['last_name'] : (isset($row['last']) ? $row['last'] : ''),
        'middle_name' => isset($row['middle_name']) ? $row['middle_name'] : (isset($row['middle']) ? $row['middle'] : ''),
        'type' => 'member' // Mark as regular member
    ];
}

// Add Sunday School children
if ($church_id) {
    $ss_sql = "SELECT id, srn, first_name, last_name, middle_name, dob, class_id FROM sunday_school WHERE church_id = ?";
    if ($is_class_leader && $class_leader_class_id) {
        $ss_sql .= " AND class_id = " . intval($class_leader_class_id);
    }
    $stmt_ss = $conn->prepare($ss_sql);
    $stmt_ss->bind_param('i', $church_id);
    $stmt_ss->execute();
    $res_ss = $stmt_ss->get_result();
    while ($row = $res_ss->fetch_assoc()) {
        $results[] = [
            'id' => $row['id'],
            'text' => trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' (SRN: ' . $row['srn'] . ')'),
            'srn' => $row['srn'],
            'class' => $row['class_id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'middle_name' => $row['middle_name'],
            'type' => 'sundayschool' // Mark as Sunday School child
        ];
    }
    $stmt_ss->close();
}
if (empty($results) && isset($_SESSION['role_id']) && $_SESSION['role_id']==1) {
    echo json_encode([
        'results'=>$results,
        'debug'=>[
            'sql'=>$sql,
            'params'=>$params,
            'types'=>$types
        ]
    ]);
} else {
    echo json_encode(['results' => $results]);
}
