<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/church_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['results' => [], 'error' => 'Authentication required']);
    exit;
}

$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin
    && !has_permission('access_ajax_members_by_church')
    && !has_permission('view_payment_bulk')) {
    http_response_code(403);
    echo json_encode(['results' => [], 'error' => 'Permission denied']);
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
$churchId = max(0, (int) ($_GET['church_id'] ?? 0));
$organizationId = max(0, (int) ($_GET['organization_id'] ?? 0));
$bibleClassId = max(0, (int) ($_GET['bible_class_id'] ?? 0));
$gender = strtolower(trim((string) ($_GET['gender'] ?? '')));

if (!$isSuperAdmin) {
    $actorChurchId = (int) get_user_church_id($conn);
    if ($actorChurchId < 1 || ($churchId > 0 && $churchId !== $actorChurchId)) {
        http_response_code(403);
        echo json_encode(['results' => [], 'error' => 'The selected church is outside your authorized scope.']);
        exit;
    }
    $churchId = $actorChurchId;
}

if ($churchId < 1) {
    echo json_encode(['results' => []]);
    exit;
}

$isClassLeader = has_role('Class Leader') || has_role('Assistant Bible Class Leader');
$classLeaderClassId = 0;
if ($isClassLeader) {
    $linkedMemberId = (int) ($_SESSION['member_id'] ?? 0);
    if ($linkedMemberId < 1 && !empty($_SESSION['user_id'])) {
        $linkStmt = $conn->prepare('SELECT member_id FROM users WHERE id = ? LIMIT 1');
        $userId = (int) $_SESSION['user_id'];
        $linkStmt->bind_param('i', $userId);
        $linkStmt->execute();
        $linkedMemberId = (int) ($linkStmt->get_result()->fetch_assoc()['member_id'] ?? 0);
        $linkStmt->close();
    }
    if ($linkedMemberId > 0) {
        $classStmt = $conn->prepare('SELECT class_id FROM members WHERE id = ? AND church_id = ? LIMIT 1');
        $classStmt->bind_param('ii', $linkedMemberId, $churchId);
        $classStmt->execute();
        $classLeaderClassId = (int) ($classStmt->get_result()->fetch_assoc()['class_id'] ?? 0);
        $classStmt->close();
    }
}

$memberWhere = ["m.status = 'active'", 'm.is_archived = 0', 'm.church_id = ?'];
$memberTypes = 'i';
$memberParams = [$churchId];
$memberJoin = '';
if ($organizationId > 0) {
    $memberJoin = ' INNER JOIN member_organizations mo ON mo.member_id = m.id';
    $memberWhere[] = 'mo.organization_id = ?';
    $memberTypes .= 'i';
    $memberParams[] = $organizationId;
}
$effectiveClassId = $classLeaderClassId > 0 ? $classLeaderClassId : $bibleClassId;
if ($effectiveClassId > 0) {
    $memberWhere[] = 'm.class_id = ?';
    $memberTypes .= 'i';
    $memberParams[] = $effectiveClassId;
}
if (in_array($gender, ['male', 'female'], true)) {
    $memberWhere[] = 'LOWER(m.gender) = ?';
    $memberTypes .= 's';
    $memberParams[] = $gender;
}
if ($query !== '') {
    $memberWhere[] = "(m.last_name LIKE CONCAT('%', ?, '%')
        OR m.first_name LIKE CONCAT('%', ?, '%')
        OR m.middle_name LIKE CONCAT('%', ?, '%')
        OR m.crn LIKE CONCAT('%', ?, '%'))";
    $memberTypes .= 'ssss';
    array_push($memberParams, $query, $query, $query, $query);
}

$memberSql = "SELECT m.id, m.first_name, m.middle_name, m.last_name, m.crn,
        class.name AS class_name
    FROM members m
    LEFT JOIN bible_classes class ON class.id = m.class_id
    $memberJoin
    WHERE " . implode(' AND ', $memberWhere) . "
    ORDER BY m.last_name, m.first_name, m.middle_name, m.id
    LIMIT 20";
$memberStmt = $conn->prepare($memberSql);
$memberStmt->bind_param($memberTypes, ...$memberParams);
$memberStmt->execute();
$memberResult = $memberStmt->get_result();
$results = [];
while ($row = $memberResult->fetch_assoc()) {
    $name = trim(implode(' ', array_filter([$row['last_name'], $row['first_name'], $row['middle_name']])));
    $results[] = [
        'id' => (int) $row['id'],
        'text' => $name . ($row['crn'] ? ' (' . $row['crn'] . ')' : ''),
        'crn' => $row['crn'],
        'class' => $row['class_name'] ?? '',
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'middle_name' => $row['middle_name'],
        'type' => 'member',
    ];
}
$memberStmt->close();

// Organization membership applies only to adult/member records. For an
// unfiltered bulk-payment search, include active Sunday School identities too.
if ($organizationId < 1) {
    $childWhere = ['child.church_id = ?', 'child.is_duplicate_archived = 0', 'child.transferred_to_member_id IS NULL'];
    $childTypes = 'i';
    $childParams = [$churchId];
    if ($effectiveClassId > 0) {
        $childWhere[] = 'child.class_id = ?';
        $childTypes .= 'i';
        $childParams[] = $effectiveClassId;
    }
    if (in_array($gender, ['male', 'female'], true)) {
        $childWhere[] = 'LOWER(child.gender) = ?';
        $childTypes .= 's';
        $childParams[] = $gender;
    }
    if ($query !== '') {
        $childWhere[] = "(child.last_name LIKE CONCAT('%', ?, '%')
            OR child.first_name LIKE CONCAT('%', ?, '%')
            OR child.middle_name LIKE CONCAT('%', ?, '%')
            OR child.srn LIKE CONCAT('%', ?, '%'))";
        $childTypes .= 'ssss';
        array_push($childParams, $query, $query, $query, $query);
    }

    $childSql = "SELECT child.id, child.first_name, child.middle_name,
            child.last_name, child.srn, child.gender, class.name AS class_name
        FROM sunday_school child
        LEFT JOIN bible_classes class ON class.id = child.class_id
        WHERE " . implode(' AND ', $childWhere) . "
        ORDER BY child.last_name, child.first_name, child.middle_name, child.id
        LIMIT 20";
    $childStmt = $conn->prepare($childSql);
    $childStmt->bind_param($childTypes, ...$childParams);
    $childStmt->execute();
    $childResult = $childStmt->get_result();
    while ($row = $childResult->fetch_assoc()) {
        $name = trim(implode(' ', array_filter([$row['last_name'], $row['first_name'], $row['middle_name']])));
        $results[] = [
            'id' => (int) $row['id'],
            'text' => $name . ($row['srn'] ? ' (' . $row['srn'] . ')' : ''),
            'srn' => $row['srn'],
            'class' => $row['class_name'] ?? '',
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'middle_name' => $row['middle_name'],
            'gender' => $row['gender'],
            'type' => 'sundayschool',
        ];
    }
    $childStmt->close();
}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
