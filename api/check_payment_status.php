<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';

try {
    $reference = $_GET['reference'] ?? '';
    
    if (!$reference) {
        echo json_encode([
            'success' => false,
            'message' => 'Payment reference is required'
        ]);
        exit;
    }
    
    // Get payment status from database
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.reference,
            p.amount,
            p.payment_date,
            p.description,
            p.mode,
            p.hubtel_reference,
            p.checkout_url,
            CASE 
                WHEN p.mode = 'mobile_money' AND p.hubtel_reference IS NOT NULL AND p.hubtel_reference != '' THEN 'completed'
                WHEN p.mode = 'mobile_money' AND (p.hubtel_reference IS NULL OR p.hubtel_reference = '') THEN 'pending'
                ELSE 'completed'
            END as status,
            m.first_name,
            m.last_name,
            m.phone,
            pt.name as payment_type_name
        FROM payments p
        LEFT JOIN members m ON p.member_id = m.id
        LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
        WHERE p.reference = ?
        ORDER BY p.id DESC
        LIMIT 1
    ");
    
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $result = $stmt->get_result();
    $payment = $result->fetch_assoc();
    
    if (!$payment) {
        echo json_encode([
            'success' => false,
            'message' => 'Payment not found'
        ]);
        exit;
    }
    
    // If payment is still pending and has a checkout URL, we can check with Hubtel
    if ($payment['status'] === 'pending' && !empty($payment['checkout_url'])) {
        // You could integrate with Hubtel status API here if needed
        // For now, we'll rely on the webhook to update the status
    }
    
    echo json_encode([
        'success' => true,
        'status' => $payment['status'],
        'payment' => [
            'id' => $payment['id'],
            'reference' => $payment['reference'],
            'amount' => $payment['amount'],
            'description' => $payment['description'],
            'payment_date' => $payment['payment_date'],
            'member_name' => trim($payment['first_name'] . ' ' . $payment['last_name']),
            'phone' => $payment['phone'],
            'payment_type' => $payment['payment_type_name']
        ],
        'message' => $payment['status'] === 'completed' ? 'Payment completed successfully' : 'Payment is still pending'
    ]);
    
} catch (Exception $e) {
    error_log("Payment status check error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error checking payment status'
    ]);
}
?>
