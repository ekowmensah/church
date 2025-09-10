<?php
/**
 * Hubtel Service Fulfillment Webhook Handler
 * 
 * This endpoint receives service fulfillment notifications after successful payments
 * via Hubtel USSD shortcode and automatically records them in the church management system.
 * 
 * Expected payload format from Hubtel Service Fulfillment:
 * {
 *   "SessionId": "3c796dac28174f739de4262d08409c51",
 *   "OrderId": "ac3307bcca7445618071e6b0e41b50b5",
 *   "ExtraData": {},
 *   "OrderInfo": {
 *     "CustomerMobileNumber": "233200585542",
 *     "Status": "Paid",
 *     "OrderDate": "2023-11-06T15:16:50.3581338+00:00",
 *     "Currency": "GHS",
 *     "Subtotal": 151.50,
 *     "Items": [...],
 *     "Payment": {
 *       "PaymentType": "mobilemoney",
 *       "AmountPaid": 151.50,
 *       "AmountAfterCharges": 150.5,
 *       "PaymentDate": "2023-11-06T15:16:50.3581338+00:00",
 *       "IsSuccessful": true
 *     }
 *   }
 * }
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../models/Payment.php';

// Set up logging
$debug_log = __DIR__.'/../logs/shortcode_webhook_debug.log';
$raw_log = __DIR__.'/../logs/shortcode_webhook_raw.log';

function log_debug($msg) {
    global $debug_log;
    file_put_contents($debug_log, date('c')." $msg\n", FILE_APPEND);
}

function log_raw($data) {
    global $raw_log;
    file_put_contents($raw_log, date('c')."\n".$data."\n", FILE_APPEND);
}

// Log raw input for debugging
$raw_input = file_get_contents('php://input');
log_raw($raw_input);
log_debug('Shortcode webhook called');

// Parse and validate input
$data = json_decode($raw_input, true);
log_debug('Webhook data: '.json_encode($data));

if (!$data) {
    log_debug('Invalid JSON data received');
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// Extract payment information from Hubtel Service Fulfillment webhook
$order_info = $data['OrderInfo'] ?? null;
if (!$order_info) {
    log_debug('Missing OrderInfo in webhook data');
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid webhook format - missing OrderInfo']);
    exit;
}

$session_id = $data['SessionId'] ?? null;
$order_id = $data['OrderId'] ?? null;
$phone = $order_info['CustomerMobileNumber'] ?? null;
$status = $order_info['Status'] ?? 'pending';
$order_date = $order_info['OrderDate'] ?? date('c');
$currency = $order_info['Currency'] ?? 'GHS';
$subtotal = $order_info['Subtotal'] ?? null;

// Extract payment details
$payment_info = $order_info['Payment'] ?? null;
$amount = $payment_info['AmountAfterCharges'] ?? $payment_info['AmountPaid'] ?? $subtotal;
$payment_type = $payment_info['PaymentType'] ?? 'mobilemoney';
$payment_date = $payment_info['PaymentDate'] ?? $order_date;
$is_successful = $payment_info['IsSuccessful'] ?? false;

// Extract item details for description
$items = $order_info['Items'] ?? [];
$description = 'USSD Shortcode Payment';
if (!empty($items)) {
    $item_names = array_column($items, 'Name');
    $description = 'USSD Payment: ' . implode(', ', $item_names);
}

$reference = $order_id;
$transaction_date = date('Y-m-d H:i:s', strtotime($payment_date));

// Validate required fields
if (!$amount || !$phone || !$reference) {
    log_debug('Missing required fields - Amount: '.$amount.', Phone: '.$phone.', Reference: '.$reference);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required payment data']);
    exit;
}

// Normalize phone number (remove country code if present, ensure 10 digits)
$phone = preg_replace('/^\+233/', '0', $phone);
$phone = preg_replace('/^233/', '0', $phone);
if (strlen($phone) === 9 && !str_starts_with($phone, '0')) {
    $phone = '0' . $phone;
}

log_debug("Processed payment data - Amount: $amount, Phone: $phone, Reference: $reference, Status: $status");

// Convert Hubtel status to our system status
$payment_status = 'Completed'; // Service fulfillment only sends successful payments

// Only process successful payments
if (strtolower($status) !== 'paid' || !$is_successful) {
    log_debug("Payment not successful. Status: $status, IsSuccessful: " . ($is_successful ? 'true' : 'false'));
    echo json_encode(['status' => 'pending', 'message' => 'Payment not successful']);
    exit;
}

try {
    // Step 1: Try to identify member by phone number
    $member_id = null;
    $member_info = null;
    $church_id = null;
    
    $member_stmt = $conn->prepare('SELECT id, CONCAT(first_name, " ", last_name) as full_name, crn, church_id FROM members WHERE phone = ? AND status = "active" LIMIT 1');
    $member_stmt->bind_param('s', $phone);
    $member_stmt->execute();
    $member_result = $member_stmt->get_result();
    
    if ($member_result->num_rows > 0) {
        $member_info = $member_result->fetch_assoc();
        $member_id = $member_info['id'];
        $church_id = $member_info['church_id'];
        log_debug("Member found by phone: ID $member_id, Name: {$member_info['full_name']}, CRN: {$member_info['crn']}");
    }
    
    // Step 2: Extract payment type and CRN from payment items
    $payment_type_id = 1; // Default to Tithe
    $crn_extracted = null;
    $donation_type = 'General Offering';
    
    // Payment type mapping from USSD to database IDs
    $type_mapping = [
        'General Offering' => 3,  // Offertory
        'Tithe' => 1,            // Tithe
        'Harvest' => 4,          // harvest
        'Building Fund' => 3,    // Offertory (fallback)
        'Other' => 3             // Offertory (fallback)
    ];
    
    // Extract payment type and CRN from items
    if (!empty($items)) {
        foreach ($items as $item) {
            $item_name = $item['Name'] ?? '';
            log_debug("Processing item: $item_name");
            
            // Extract donation type (before " - CRN:")
            if (preg_match('/^([^-]+?)(?:\s*-\s*CRN:|$)/', $item_name, $type_matches)) {
                $donation_type = trim($type_matches[1]);
                if (isset($type_mapping[$donation_type])) {
                    $payment_type_id = $type_mapping[$donation_type];
                    log_debug("Payment type mapped: '$donation_type' -> ID $payment_type_id");
                }
            }
            
            // Extract CRN
            if (preg_match('/CRN:\s*([A-Z0-9]{3,10})/i', $item_name, $crn_matches)) {
                $crn_extracted = strtoupper(trim($crn_matches[1]));
                log_debug("CRN extracted from item name '$item_name': $crn_extracted");
                break;
            }
        }
    }
    
    // Step 3: If no member found by phone, try CRN lookup
    if (!$member_id) {
        log_debug("No member found with phone: $phone, attempting CRN extraction");
        
        // Fallback: check description and reference if CRN not found in items
        if (!$crn_extracted) {
            $combined_text = $reference . ' ' . $description;
            if (preg_match('/CRN:\s*([A-Z0-9]{3,10})/i', $combined_text, $matches)) {
                $crn_extracted = strtoupper(trim($matches[1]));
                log_debug("CRN extracted from description/reference: $crn_extracted");
            }
        }
        
        // Look up member by extracted CRN
        if ($crn_extracted) {
            $crn_stmt = $conn->prepare('SELECT id, CONCAT(first_name, " ", last_name) as full_name, crn, church_id, phone FROM members WHERE crn = ? AND status = "active" LIMIT 1');
            $crn_stmt->bind_param('s', $crn_extracted);
            $crn_stmt->execute();
            $crn_result = $crn_stmt->get_result();
            
            if ($crn_result->num_rows > 0) {
                $member_info = $crn_result->fetch_assoc();
                $member_id = $member_info['id'];
                $church_id = $member_info['church_id'];
                log_debug("Member found by CRN: ID $member_id, Name: {$member_info['full_name']}, CRN: {$member_info['crn']}");
                
                // Update member's phone number if it's different or empty
                if (empty($member_info['phone']) || $member_info['phone'] !== $phone) {
                    $update_phone_stmt = $conn->prepare('UPDATE members SET phone = ? WHERE id = ?');
                    $update_phone_stmt->bind_param('si', $phone, $member_id);
                    $update_phone_stmt->execute();
                    log_debug("Updated member phone from '{$member_info['phone']}' to '$phone'");
                }
            } else {
                log_debug("No member found with CRN: $crn_extracted");
            }
        } else {
            log_debug("No CRN found in payment data");
        }
    }
    
    // Step 3: Record payment
    $paymentModel = new Payment();
    
    if ($member_id) {
        // Member identified - record as regular payment
        $payment_data = [
            'member_id' => $member_id,
            'amount' => floatval($amount),
            'description' => "Shortcode Payment - $description",
            'payment_date' => $transaction_date,
            'client_reference' => $reference,
            'status' => $payment_status,
            'church_id' => $church_id,
            'payment_type_id' => $payment_type_id, // Mapped from USSD selection
            'recorded_by' => 'Shortcode Payment',
            'mode' => 'Mobile Money'
        ];
        
        log_debug('Recording payment for identified member: '.json_encode($payment_data));
        $result = $paymentModel->add($conn, $payment_data);
        
        if ($result && isset($result['id'])) {
            log_debug("Payment recorded successfully with ID: {$result['id']}");
            
            // Send SMS confirmation
            require_once __DIR__.'/../includes/sms.php';
            
            $church_stmt = $conn->prepare('SELECT name FROM churches WHERE id = ?');
            $church_stmt->bind_param('i', $church_id);
            $church_stmt->execute();
            $church = $church_stmt->get_result()->fetch_assoc();
            $church_name = $church['name'] ?? 'Church';
            
            $formatted_amount = number_format($amount, 2);
            $sms_message = "Hi {$member_info['full_name']}, your shortcode payment of ₵$formatted_amount has been received by $church_name. Thank you for your contribution!";
            
            $sms_result = send_sms($phone, $sms_message);
            log_debug('SMS confirmation sent: '.json_encode($sms_result));
            
            // Log SMS
            $sms_status = (isset($sms_result['status']) && $sms_result['status'] === 'success') ? 'success' : 'fail';
            $sms_log_stmt = $conn->prepare('INSERT INTO sms_logs (member_id, phone, message, type, status, provider, sent_at, response) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)');
            $sms_type = 'shortcode_payment_confirmation';
            $provider = 'arkesel';
            $sms_response = json_encode($sms_result);
            $sms_log_stmt->bind_param('issssss', $member_id, $phone, $sms_message, $sms_type, $sms_status, $provider, $sms_response);
            $sms_log_stmt->execute();
            
        } else {
            log_debug('Failed to record payment: '.json_encode($result));
        }
        
    } else {
        // Member not identified - record as unmatched payment for manual assignment
        log_debug('Member not identified, recording as unmatched payment');
        
        $unmatched_stmt = $conn->prepare('
            INSERT INTO unmatched_payments (
                phone, amount, reference, description, transaction_date, 
                raw_data, status, payment_type_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        
        $raw_data_json = json_encode($data);
        $unmatched_stmt->bind_param('sdsssssi', $phone, $amount, $reference, $description, $transaction_date, $raw_data_json, $payment_status, $payment_type_id);
        $unmatched_stmt->execute();
        
        log_debug('Unmatched payment recorded for manual assignment');
        
        // Notify admin about unmatched payment
        // You can implement admin notification logic here
    }
    
    // Respond success to Hubtel
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Payment processed']);
    log_debug('Webhook processed successfully');
    
} catch (Exception $e) {
    log_debug('Error processing webhook: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
?>
