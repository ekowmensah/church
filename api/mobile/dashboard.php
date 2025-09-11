<?php
/**
 * Mobile API Dashboard Endpoint
 * Returns member dashboard data
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/jwt_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Authenticate request
$auth = authenticate_mobile_request();
if (!$auth) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$member_id = $auth['member_id'];

try {
    // Get member basic info
    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, crn, photo, dob FROM members WHERE id = ? AND status = 'active'");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();

    if (!$member) {
        http_response_code(404);
        echo json_encode(['error' => 'Member not found']);
        exit();
    }

    // Check if today is birthday
    $is_birthday = false;
    if (!empty($member['dob'])) {
        $dob = date_create($member['dob']);
        if ($dob && date('m-d') == $dob->format('m-d')) {
            $is_birthday = true;
        }
    }

    // Get attendance percentage
    $attendance_percent = 0;
    $att_total = $att_present = 0;
    $stmt = $conn->prepare("SELECT status FROM attendance_records WHERE member_id = ?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $att_total++;
        if (strtolower($row['status']) === 'present') $att_present++;
    }
    $attendance_percent = $att_total ? round(($att_present/$att_total)*100) : 0;

    // Get total payments
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE member_id = ? AND ((reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL))");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $total_payments = $stmt->get_result()->fetch_assoc()['total'];

    // Get Bible class
    $bible_class = 'Not Assigned';
    $stmt = $conn->prepare("SELECT bc.name FROM members m LEFT JOIN bible_classes bc ON m.class_id = bc.id WHERE m.id = ?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $class_result = $stmt->get_result()->fetch_assoc();
    if ($class_result && $class_result['name']) {
        $bible_class = $class_result['name'];
    }

    // Get organizations
    $organizations = [];
    $stmt = $conn->prepare("SELECT o.name FROM member_organizations mo INNER JOIN organizations o ON mo.organization_id = o.id WHERE mo.member_id = ? ORDER BY o.name");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $org_res = $stmt->get_result();
    while ($row = $org_res->fetch_assoc()) {
        $organizations[] = $row['name'];
    }

    // Get recent payments (last 5)
    $recent_payments = [];
    $stmt = $conn->prepare("SELECT p.amount, p.payment_date, pt.name as payment_type FROM payments p LEFT JOIN payment_types pt ON p.payment_type_id = pt.id WHERE p.member_id = ? AND ((p.reversal_approved_at IS NULL) OR (p.reversal_undone_at IS NOT NULL)) ORDER BY p.payment_date DESC LIMIT 5");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $pay_res = $stmt->get_result();
    while ($row = $pay_res->fetch_assoc()) {
        $recent_payments[] = [
            'amount' => floatval($row['amount']),
            'date' => $row['payment_date'],
            'type' => $row['payment_type'] ?? 'Payment'
        ];
    }

    // Get upcoming events (next 5)
    $upcoming_events = [];
    $stmt = $conn->prepare("SELECT id, name, event_date, location FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5");
    $stmt->execute();
    $event_res = $stmt->get_result();
    while ($row = $event_res->fetch_assoc()) {
        $upcoming_events[] = [
            'id' => intval($row['id']),
            'name' => $row['name'],
            'date' => $row['event_date'],
            'location' => $row['location']
        ];
    }

    // Profile photo URL
    $photo_url = null;
    if (!empty($member['photo']) && file_exists(__DIR__ . '/../../uploads/members/' . $member['photo'])) {
        $photo_url = BASE_URL . '/uploads/members/' . rawurlencode($member['photo']);
    }

    // Return dashboard data
    echo json_encode([
        'success' => true,
        'data' => [
            'member' => [
                'name' => trim($member['first_name'] . ' ' . $member['middle_name'] . ' ' . $member['last_name']),
                'first_name' => $member['first_name'],
                'crn' => $member['crn'],
                'photo_url' => $photo_url,
                'is_birthday' => $is_birthday
            ],
            'stats' => [
                'attendance_percent' => $attendance_percent,
                'total_payments' => floatval($total_payments),
                'bible_class' => $bible_class,
                'organizations_count' => count($organizations)
            ],
            'organizations' => $organizations,
            'recent_payments' => $recent_payments,
            'upcoming_events' => $upcoming_events
        ]
    ]);

} catch (Exception $e) {
    error_log("Mobile dashboard error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
