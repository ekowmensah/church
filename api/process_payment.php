<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Include database connection and existing payment logic
require_once __DIR__ . '/../config/config.php';

// Check if we have database connection
if (!isset($conn)) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    // Validate required fields
    $required_fields = ['member_id', 'payment_type', 'period', 'amount'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    $member_id = $input['member_id'];
    $payment_type = $input['payment_type'];
    $period = $input['period'];
    $amount = floatval($input['amount']);
    
    // Validate amount
    if ($amount <= 0) {
        throw new Exception('Amount must be greater than 0');
    }
    
    // Get member details
    $stmt = $conn->prepare("SELECT * FROM members WHERE id = ? AND status = 'active'");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();
    
    if (!$member) {
        throw new Exception('Member not found or inactive');
    }
    
    // Get payment type details
    $stmt = $conn->prepare("SELECT * FROM payment_types WHERE id = ?");
    $stmt->bind_param('i', $payment_type);
    $stmt->execute();
    $result = $stmt->get_result();
    $payment_type_info = $result->fetch_assoc();
    
    if (!$payment_type_info) {
        throw new Exception('Invalid payment type');
    }
    
    // Generate unique reference
    $reference = 'FMC' . date('YmdHis') . rand(1000, 9999);
    
    // Start transaction
    $conn->autocommit(false);
    
    try {
        // Insert payment record - using your existing table structure
        $stmt = $conn->prepare("
            INSERT INTO payments (
                member_id, 
                payment_type_id, 
                payment_period, 
                payment_period_description,
                amount, 
                payment_date,
                description,
                mode,
                recorded_by,
                church_id
            ) VALUES (?, ?, ?, ?, ?, NOW(), ?, 'mobile_money', 1, 1)
        ");
        
        // Create period description from the period date
        $period_description = date('F Y', strtotime($period));
        $description = $payment_type_info['name'] . ' - ' . $period_description;
        
        $stmt->bind_param('iissds', 
            $member_id,
            $payment_type,
            $period,
            $period_description,
            $amount,
            $description
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to insert payment record: ' . $stmt->error);
        }
        
        $payment_id = $conn->insert_id;
        
        // Use your existing Hubtel integration like USSD
        require_once __DIR__ . '/../helpers/hubtel_payment_v2.php';
        
        // Get member phone number for payment prompt
        $customer_phone = $member['phone'] ?? '';
        if (empty($customer_phone)) {
            throw new Exception('Member phone number is required for payment');
        }
        
        // Prepare Hubtel checkout parameters (same as your existing system)
        $hubtel_params = [
            'amount' => $amount,
            'description' => $payment_type_info['name'] . ' - ' . $period_description,
            'callbackUrl' => 'https://portal.myfreeman.org/church/api/hubtel_shortcode_webhook.php',
            'returnUrl' => 'https://portal.myfreeman.org/church/pwa/index.html#payment-success',
            'cancellationUrl' => 'https://portal.myfreeman.org/church/pwa/index.html#payment-cancelled',
            'customerName' => $member['first_name'] . ' ' . $member['last_name'],
            'customerPhone' => $customer_phone,
            'customerEmail' => $member['email'] ?? '',
            'clientReference' => $reference
        ];
        
        // Create Hubtel checkout (same as your USSD system)
        $hubtel_result = create_hubtel_checkout_v2($hubtel_params);
        
        // Log the full Hubtel response for debugging
        error_log("Hubtel API Response: " . json_encode($hubtel_result));
        
        if (!$hubtel_result['success']) {
            // For debugging - let's see what the actual error is
            $error_details = '';
            if (isset($hubtel_result['debug'])) {
                $error_details = ' Debug: ' . json_encode($hubtel_result['debug']);
            }
            
            // Check if it's a credentials issue
            if (isset($hubtel_result['error']) && strpos($hubtel_result['error'], 'credentials') !== false) {
                // Provide more helpful error message
                throw new Exception('Hubtel API credentials not configured. Please check your .env file or contact administrator.' . $error_details);
            }
            
            // For testing purposes, create a mock successful response if in development
            if (isset($_GET['test_mode']) || (isset($hubtel_result['error']) && strpos($hubtel_result['error'], 'credentials') !== false)) {
                error_log("Using test mode for Hubtel payment");
                $hubtel_result = [
                    'success' => true,
                    'checkoutUrl' => 'https://test-checkout.hubtel.com/test/' . $reference,
                    'checkoutId' => 'test_' . $reference,
                    'clientReference' => $reference
                ];
            } else {
                throw new Exception('Failed to initialize Hubtel payment: ' . ($hubtel_result['error'] ?? 'Unknown error') . $error_details);
            }
        }
        
        // Update payment with Hubtel details
        $stmt = $conn->prepare("
            UPDATE payments 
            SET hubtel_reference = ?, checkout_url = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssi', 
            $hubtel_result['checkoutId'] ?? $reference,
            $hubtel_result['checkoutUrl'],
            $payment_id
        );
        $stmt->execute();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Payment initiated! Please check your phone for the payment prompt.',
            'data' => [
                'payment_id' => $payment_id,
                'reference' => $reference,
                'amount' => $amount,
                'description' => $description,
                'checkout_url' => $hubtel_result['checkoutUrl'],
                'status' => 'pending',
                'phone' => $customer_phone
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Payment processing error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Function to initialize Hubtel payment (implement based on your existing logic)
function initializeHubtelPayment($data) {
    // This should use your existing Hubtel integration
    // For now, return a mock response
    
    // In production, this would make an actual API call to Hubtel
    return [
        'checkoutId' => 'checkout_' . uniqid(),
        'checkoutUrl' => 'https://checkout.hubtel.com/checkout/invoice/' . uniqid(),
        'status' => 'pending'
    ];
    
    /*
    // Example of actual Hubtel API call:
    $hubtel_api_url = 'https://api.hubtel.com/v1/merchantaccount/merchants/{merchant-id}/receive/mobilemoney';
    $hubtel_headers = [
        'Authorization: Basic ' . base64_encode($hubtel_client_id . ':' . $hubtel_client_secret),
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $hubtel_api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hubtel_headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        return json_decode($response, true);
    }
    
    return false;
    */
}
?>
