<?php
require_once __DIR__.'/../config/config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$search_term = isset($_GET['q']) ? trim($_GET['q']) : '';
$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : $_SESSION['church_id'];
$organization_id = isset($_GET['organization_id']) ? intval($_GET['organization_id']) : 0;
$bible_class_id = isset($_GET['bible_class_id']) ? intval($_GET['bible_class_id']) : 0;

if (strlen($search_term) < 2) {
    echo json_encode([]);
    exit;
}

// Search members by name or CRN
$search_pattern = '%' . $search_term . '%';

// Build query with optional joins for filtering
$query = "SELECT m.id, m.first_name, m.last_name, m.crn, m.phone FROM members m";
$joins = [];
$where_conditions = [
    "m.church_id = ?",
    "(CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR m.crn LIKE ? OR m.phone LIKE ?)",
    "m.status = 'active'"
];
$params = [$church_id, $search_pattern, $search_pattern, $search_pattern];
$param_types = 'isss';

// Add organization filter if specified
if ($organization_id) {
    $joins[] = "INNER JOIN member_organizations mo ON m.id = mo.member_id";
    $where_conditions[] = "mo.organization_id = ?";
    $params[] = $organization_id;
    $param_types .= 'i';
}

// Add bible class filter if specified
if ($bible_class_id) {
    $where_conditions[] = "m.class_id = ?";
    $params[] = $bible_class_id;
    $param_types .= 'i';
}

// Construct final query
if (!empty($joins)) {
    $query .= " " . implode(" ", $joins);
}
$query .= " WHERE " . implode(" AND ", $where_conditions);
$query .= " ORDER BY m.first_name, m.last_name LIMIT 20";

$stmt = $conn->prepare($query);
if (!$stmt) {
    echo json_encode([]);
    exit;
}

$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$members = [];
while ($row = $result->fetch_assoc()) {
    $members[] = [
        'id' => $row['id'],
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'crn' => $row['crn'],
        'phone' => $row['phone'],
        'full_name' => $row['first_name'] . ' ' . $row['last_name']
    ];
}

// Temporary debug output
if (empty($members)) {
    error_log("AJAX Debug - No members found:");
    error_log("Query: " . $query);
    error_log("Params: " . print_r($params, true));
    error_log("Search term: " . $search_term);
    error_log("Church ID: " . $church_id);
    error_log("Organization ID: " . $organization_id);
    error_log("Bible Class ID: " . $bible_class_id);
}

echo json_encode($members);
?>
