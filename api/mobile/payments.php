<?php
/**
 * Mobile API Payments Endpoint
 * Handles payment creation and history for members
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/jwt_helper.php';
require_once __DIR__ . '/../../helpers/hubtel_payment.php';

// Authenticate request
$auth = authenticate_mobile_request();
if (!$auth) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$member_id = $auth['member_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get payment history
        $start_date = $_GET['start_date'] ?? '';
        $end_date = $_GET['end_date'] ?? '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

        $where = 'p.member_id = ?';
        $params = [$member_id];
        $types = 'i';

        if ($start_date) {
            $where .= ' AND p.payment_date >= ?';
            $params[] = $start_date . ' 00:00:00';
            $types .= 's';
        }
        if ($end_date) {
            $where .= ' AND p.payment_date <= ?';
            $params[] = $end_date . ' 23:59:59';
            $types .= 's';
        }

        // Get payments
        $sql = "SELECT p.id, p.amount, p.payment_date, p.mode, p.description, pt.name AS payment_type, p.payment_period, p.payment_period_description
                FROM payments p 
                LEFT JOIN payment_types pt ON p.payment_type_id = pt.id 
                WHERE ($where) AND ((p.reversal_approved_at IS NULL) OR (p.reversal_undone_at IS NOT NULL)) 
                ORDER BY p.payment_date DESC, p.id DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $conn->prepare($sql);
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $payments = [];
        while ($row = $result->fetch_assoc()) {
            $payments[] = [
                'id' => intval($row['id']),
                'amount' => floatval($row['amount']),
                'date' => $row['payment_date'],
                'mode' => $row['mode'],
                'type' => $row['payment_type'] ?? 'Payment',
                'description' => $row['description'],
                'period' => $row['payment_period_description'] ?? $row['payment_period']
            ];
        }

        // Get summary
        $sum_sql = "SELECT SUM(amount) as total, COUNT(*) as count, MAX(payment_date) as last_payment 
                    FROM payments 
                    WHERE member_id = ? AND ((reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL))";
        $sum_params = [$member_id];
        $sum_types = 'i';

        if ($start_date) {
            $sum_sql .= ' AND payment_date >= ?';
            $sum_params[] = $start_date . ' 00:00:00';
            $sum_types .= 's';
        }
        if ($end_date) {
            $sum_sql .= ' AND payment_date <= ?';
            $sum_params[] = $end_date . ' 23:59:59';
            $sum_types .= 's';
        }

        $sum_stmt = $conn->prepare($sum_sql);
        $sum_stmt->bind_param($sum_types, ...$sum_params);
        $sum_stmt->execute();
        $summary = $sum_stmt->get_result()->fetch_assoc();

        echo json_encode([
            'success' => true,
            'data' => [
                'payments' => $payments,
                'summary' => [
                    'total_amount' => floatval($summary['total'] ?? 0),
                    'total_count' => intval($summary['count'] ?? 0),
                    'last_payment' => $summary['last_payment']
                ],
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => count($payments) === $limit
                ]
            ]
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Create new payment (Hubtel checkout)
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['payment_type_id']) || !isset($input['amount'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Payment type and amount required']);
            exit();
        }

        // Get member details
        $stmt = $conn->prepare("SELECT crn, CONCAT(first_name, ' ', IFNULL(middle_name, ''), ' ', last_name) as full_name, phone, email FROM members WHERE id = ? AND status = 'active'");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();

        if (!$member) {
            http_response_code(404);
            echo json_encode(['error' => 'Member not found']);
            exit();
        }

        // Get payment type
        $stmt = $conn->prepare("SELECT name FROM payment_types WHERE id = ? AND active = 1");
        $stmt->bind_param('i', $input['payment_type_id']);
        $stmt->execute();
        $payment_type = $stmt->get_result()->fetch_assoc();

        if (!$payment_type) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payment type']);
            exit();
        }

        // Prepare Hubtel checkout data
        $amount = floatval($input['amount']);
        $payment_period = $input['payment_period'] ?? date('Y-m');
        $payment_period_description = $input['payment_period_description'] ?? date('F Y');
        
        $checkout_data = [
            'amount' => $amount,
            'currency' => 'GHS',
            'description' => $payment_type['name'] . ' - ' . $payment_period_description,
            'clientReference' => 'MOBILE_' . $member_id . '_' . time(),
            'callbackUrl' => BASE_URL . '/views/hubtel_callback.php',
            'returnUrl' => 'church://payment/success',
            'cancellationUrl' => 'church://payment/cancelled',
            'customerName' => trim($member['full_name']),
            'customerEmail' => $member['email'] ?: $member['phone'] . '@church.com',
            'customerMsisdn' => $member['phone']
        ];

        // Create Hubtel checkout
        $checkout_response = create_hubtel_checkout($checkout_data);

        if ($checkout_response && isset($checkout_response['checkoutUrl'])) {
            // Store payment intent in session or database for callback processing
            $payment_data = [
                'member_id' => $member_id,
                'payment_type_id' => $input['payment_type_id'],
                'amount' => $amount,
                'payment_period' => $payment_period,
                'payment_period_description' => $payment_period_description,
                'mode' => 'Mobile Money',
                'recorded_by' => 'Mobile App',
                'client_reference' => $checkout_data['clientReference']
            ];

            // Store in temporary table or session for callback processing
            $stmt = $conn->prepare("INSERT INTO payment_intents (client_reference, member_id, payment_data, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE payment_data = VALUES(payment_data)");
            $stmt->bind_param('sis', $checkout_data['clientReference'], $member_id, json_encode($payment_data));
            $stmt->execute();

            echo json_encode([
                'success' => true,
                'data' => [
                    'checkout_url' => $checkout_response['checkoutUrl'],
                    'client_reference' => $checkout_data['clientReference']
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create payment checkout']);
        }

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("Mobile payments error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
