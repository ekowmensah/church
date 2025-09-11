<?php
/**
 * Mobile API Authentication Endpoint
 * Handles member login for mobile app
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/jwt_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['crn']) || !isset($input['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CRN and password required']);
    exit();
}

$crn = trim($input['crn']);
$password = trim($input['password']);

try {
    // Check member credentials
    $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, phone, photo, status, password_hash FROM members WHERE crn = ? AND status = 'active'");
    $stmt->bind_param('s', $crn);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();

    if (!$member) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        exit();
    }

    // Verify password (assuming you have password hashing)
    if (!password_verify($password, $member['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        exit();
    }

    // Generate JWT token
    $payload = [
        'member_id' => $member['id'],
        'crn' => $crn,
        'exp' => time() + (30 * 24 * 60 * 60) // 30 days
    ];
    
    $token = generate_jwt($payload);

    // Get member's profile photo URL
    $photo_url = null;
    if (!empty($member['photo']) && file_exists(__DIR__ . '/../../uploads/members/' . $member['photo'])) {
        $photo_url = BASE_URL . '/uploads/members/' . rawurlencode($member['photo']);
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'token' => $token,
        'member' => [
            'id' => $member['id'],
            'name' => trim($member['first_name'] . ' ' . $member['middle_name'] . ' ' . $member['last_name']),
            'first_name' => $member['first_name'],
            'email' => $member['email'],
            'phone' => $member['phone'],
            'photo_url' => $photo_url,
            'crn' => $crn
        ]
    ]);

} catch (Exception $e) {
    error_log("Mobile auth error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
