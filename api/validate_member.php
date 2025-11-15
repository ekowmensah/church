<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}


session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';


// Authentication check
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}


try {
    // Get identifier from GET parameter (from app.js) or POST data
    $identifier = $_GET['identifier'] ?? '';
    
    if (!$identifier) {
        // Fallback to POST data for backward compatibility
        $data = json_decode(file_get_contents('php://input'), true);
        $identifier = $data['crn'] ?? $data['phone'] ?? '';
    }
    
    if (!$identifier) {
        echo json_encode([
            'valid' => false,
            'message' => 'No identifier provided'
        ]);
        exit;
    }
    
    // Try to find member by CRN first, then by phone
    $stmt = $conn->prepare('SELECT id, crn, phone, CONCAT(first_name, " ", last_name) as name, first_name, last_name FROM members WHERE (crn = ? OR phone = ?) AND status = "active"');
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();
    
    if ($member) {
        echo json_encode([
            'valid' => true,
            'member' => [
                'id' => $member['id'],
                'crn' => $member['crn'],
                'phone' => $member['phone'],
                'name' => $member['name'],
                'first_name' => $member['first_name'],
                'last_name' => $member['last_name'],
                'default_amount' => 50.00 // Default amount
            ]
        ]);
    } else {
        echo json_encode([
            'valid' => false,
            'message' => 'Member not found. Please check your CRN or phone number.'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Member validation error: " . $e->getMessage());
    echo json_encode([
        'valid' => false,
        'message' => 'An error occurred while validating member'
    ]);
}
