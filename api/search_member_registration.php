<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__.'/../config/config.php';

try {
    $q = trim($_GET['q'] ?? '');
    $excluded_crns = trim($_GET['exclude'] ?? ''); // comma-separated CRNs to exclude
    
    if (strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }
    
    // Search by CRN, name (first_name, last_name), or phone
    $search_term = '%' . $q . '%';
    $sql = '
        SELECT id, crn, first_name, last_name, phone, CONCAT(first_name, " ", last_name) as full_name
        FROM members
        WHERE status = "active" AND (crn LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?)';
    
    // Add exclusion clause if provided
    if (!empty($excluded_crns)) {
        $excluded_list = explode(',', $excluded_crns);
        $excluded_list = array_map('trim', $excluded_list);
        $excluded_list = array_map(function($x) use ($conn) { return "'" . $conn->real_escape_string($x) . "'"; }, $excluded_list);
        $sql .= ' AND crn NOT IN (' . implode(',', $excluded_list) . ')';
    }
    
    $sql .= ' LIMIT 10';
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssss', $search_term, $search_term, $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $results = [];
    while ($row = $result->fetch_assoc()) {
        $results[] = [
            'id' => $row['crn'],  // Use CRN as the Select2 value for proper exclusion
            'text' => $row['crn'] . ' - ' . $row['full_name'] . ' (' . $row['phone'] . ')',
            'full_name' => $row['full_name'],
            'phone' => $row['phone'],
            'crn' => $row['crn']
        ];
    }
    $stmt->close();
    
    echo json_encode(['results' => $results]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
