<?php
require_once __DIR__.'/../config/config.php';
header('Content-Type: application/json');

try {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $role_id = isset($_GET['role_id']) ? intval($_GET['role_id']) : 0;
    
    if (!$role_id) {
        echo json_encode(['results'=>[]]);
        exit;
    }
    
    // Build query based on search term
    if ($q !== '') {
        $sql = "SELECT u.id, u.name, u.email FROM users u 
                INNER JOIN user_roles ur ON u.id = ur.user_id 
                WHERE ur.role_id = ? 
                AND (u.name LIKE ? OR u.email LIKE ?)
                ORDER BY u.name ASC LIMIT 20";
        $search_term = "%$q%";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iss', $role_id, $search_term, $search_term);
    } else {
        $sql = "SELECT u.id, u.name, u.email FROM users u 
                INNER JOIN user_roles ur ON u.id = ur.user_id 
                WHERE ur.role_id = ? 
                ORDER BY u.name ASC LIMIT 20";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $role_id);
    }
    
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $results = [];
    while($row = $result->fetch_assoc()) {
        $results[] = [
            'id' => $row['id'],
            'text' => $row['name'] . ' (' . $row['email'] . ')'
        ];
    }
    
    echo json_encode(['results' => $results]);
    $stmt->close();
    
} catch (Exception $e) {
    echo json_encode(['results' => [], 'error' => $e->getMessage()]);
}
