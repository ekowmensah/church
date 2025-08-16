<?php
// Hubtel callback handler V2: receives payment status update from Hubtel's new API
// This is the endpoint you configure in Hubtel dashboard as the callbackUrl
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../controllers/PaymentController.php';

// Log raw input for debugging
$log_file = __DIR__.'/../logs/hubtel_callback_v2.log';
file_put_contents($log_file, date('c')."\n".file_get_contents('php://input')."\n", FILE_APPEND);

// Parse the callback data
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo 'Invalid callback data';
    exit;
}

// Log the structured data for debugging
file_put_contents($log_file, date('c')."\nStructured data: ".json_encode($data, JSON_PRETTY_PRINT)."\n", FILE_APPEND);

// Extract payment details based on the new API response format
// Note: The exact structure may vary, adjust based on actual callbacks received
$status = $data['status'] ?? ($data['Status'] ?? '');
$clientReference = $data['clientReference'] ?? ($data['ClientReference'] ?? '');
$amount = $data['amount'] ?? ($data['Amount'] ?? 0);
$checkoutId = $data['checkoutId'] ?? ($data['CheckoutId'] ?? '');
$transactionId = $data['transactionId'] ?? ($data['TransactionId'] ?? '');

// Log the extracted data
$extracted_data = [
    'status' => $status,
    'clientReference' => $clientReference,
    'amount' => $amount,
    'checkoutId' => $checkoutId,
    'transactionId' => $transactionId
];
file_put_contents($log_file, date('c')."\nExtracted data: ".json_encode($extracted_data, JSON_PRETTY_PRINT)."\n", FILE_APPEND);

// Validate required data
if (!$clientReference || !$status) {
    http_response_code(400);
    echo 'Missing required fields';
    file_put_contents($log_file, date('c')."\nError: Missing required fields\n", FILE_APPEND);
    exit;
}

try {
    // Update payment intent status
    $stmt = $conn->prepare('UPDATE payment_intents SET status = ?, transaction_id = ?, updated_at = NOW() WHERE client_reference = ?');
    $stmt->bind_param('sss', $status, $transactionId, $clientReference);
    $stmt->execute();
    
    // If payment was successful, create the actual payment record
    if (strtolower($status) === 'success' || strtolower($status) === 'completed') {
        // Get payment intent details
        $stmt = $conn->prepare('SELECT * FROM payment_intents WHERE client_reference = ?');
        $stmt->bind_param('s', $clientReference);
        $stmt->execute();
        $result = $stmt->get_result();
        $paymentIntent = $result->fetch_assoc();
        
        if ($paymentIntent) {
            // Create payment record using PaymentController
            $paymentController = new PaymentController();
            $paymentData = [
                'member_id' => $paymentIntent['member_id'] ?? null,
                'amount' => $paymentIntent['amount'],
                'type' => 'Online Payment (Hubtel)',
                'payment_date' => date('Y-m-d'),
                'description' => $paymentIntent['description'],
                'transaction_id' => $transactionId,
                'client_reference' => $clientReference
            ];
            
            $paymentId = $paymentController->create($paymentData);
            
            if ($paymentId) {
                file_put_contents($log_file, date('c')."\nPayment record created: $paymentId\n", FILE_APPEND);
            } else {
                file_put_contents($log_file, date('c')."\nFailed to create payment record\n", FILE_APPEND);
            }
        }
    }
    
    // Respond OK to Hubtel
    http_response_code(200);
    echo 'OK';
    
} catch (Exception $e) {
    // Log error
    file_put_contents($log_file, date('c')."\nError: ".$e->getMessage()."\n", FILE_APPEND);
    
    // Still respond with 200 to acknowledge receipt (Hubtel may retry otherwise)
    http_response_code(200);
    echo 'OK';
}
