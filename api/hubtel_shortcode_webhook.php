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
    
    // Step 2: Extract payment type and member info from payment items
    $payment_type_id = 1; // Default to first available payment type
    $target_member_id = null;
    $payer_member_id = null;
    $donation_type = 'Donation';
    
    // Get payment types from database for dynamic mapping
    $payment_types = [];
    $types_result = $conn->query("SELECT id, name FROM payment_types WHERE active = 1 ORDER BY name ASC");
    while ($row = $types_result->fetch_assoc()) {
        $payment_types[strtolower($row['name'])] = $row['id'];
    }
    
    // Set default payment type to first available
    if (!empty($payment_types)) {
        $payment_type_id = reset($payment_types);
    }
    
    // Extract payment info from items
    $payment_period = null;
    $payment_period_description = null;
    
    if (!empty($items)) {
        foreach ($items as $item) {
            $item_name = $item['Name'] ?? '';
            log_debug("Processing item: $item_name");
            
            // Parse new format: "PaymentType - Member ID: X, Period: Y" or "PaymentType - Payer ID: X, Target ID: Y, Period: Z"
            if (preg_match('/^([^-]+?)\s*-\s*(.+)$/', $item_name, $matches)) {
                $donation_type = trim($matches[1]);
                $member_info = trim($matches[2]);
                
                // Map payment type name to ID
                $type_key = strtolower($donation_type);
                if (isset($payment_types[$type_key])) {
                    $payment_type_id = $payment_types[$type_key];
                    log_debug("Payment type mapped: '$donation_type' -> ID $payment_type_id");
                }
                
                // Extract payment period from member info
                if (preg_match('/Period:\s*([0-9-]+)/', $member_info, $period_matches)) {
                    $payment_period = $period_matches[1];
                    $payment_period_description = date('F Y', strtotime($payment_period));
                    log_debug("Payment period extracted: $payment_period ($payment_period_description)");
                }
                
                // Extract member IDs from member info
                if (preg_match('/Member ID:\s*(\d+)/', $member_info, $member_matches)) {
                    // Self payment by registered member
                    $target_member_id = intval($member_matches[1]);
                    $payer_member_id = $target_member_id;
                    log_debug("Self payment - Member ID: $target_member_id");
                } elseif (preg_match('/Target ID:\s*(\d+),\s*Payer ID:\s*(\d+)/', $member_info, $target_first_matches)) {
                    // Registered member paying for another member (Target ID first format)
                    $target_member_id = intval($target_first_matches[1]);
                    $payer_member_id = intval($target_first_matches[2]);
                    log_debug("Cross payment (Target first) - Target ID: $target_member_id, Payer ID: $payer_member_id");
                } elseif (preg_match('/Payer ID:\s*(\d+),\s*Target ID:\s*(\d+)/', $member_info, $payer_matches)) {
                    // Registered member paying for another member (Payer ID first format - legacy)
                    $payer_member_id = intval($payer_matches[1]);
                    $target_member_id = intval($payer_matches[2]);
                    log_debug("Cross payment (Payer first) - Payer ID: $payer_member_id, Target ID: $target_member_id");
                } elseif (preg_match('/Phone:\s*([^,]+)\s*\(unregistered\)(?:,\s*Target ID:\s*(\d+))?/', $member_info, $phone_matches)) {
                    // Unregistered user payment
                    if (isset($phone_matches[2])) {
                        // Unregistered user paying for a member
                        $target_member_id = intval($phone_matches[2]);
                        log_debug("Unregistered user paying for member ID: $target_member_id");
                    } else {
                        // Unregistered user paying for themselves
                        log_debug("Unregistered user self payment");
                    }
                }
                break;
            }
            
            // Fallback: Legacy CRN extraction for backward compatibility
            if (preg_match('/CRN:\s*([A-Z0-9-]+)/i', $item_name, $crn_matches)) {
                $crn_extracted = strtoupper(trim($crn_matches[1]));
                log_debug("Legacy CRN extracted: $crn_extracted");
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
    
    // Step 3: Determine final member ID for payment attribution
    $final_member_id = null;
    $final_church_id = null;
    
    // Priority: target_member_id > payer_member_id > member_id (from phone lookup)
    if ($target_member_id) {
        $final_member_id = $target_member_id;
        // Get church_id for target member
        $target_stmt = $conn->prepare('SELECT church_id FROM members WHERE id = ? AND status = "active"');
        $target_stmt->bind_param('i', $target_member_id);
        $target_stmt->execute();
        $target_result = $target_stmt->get_result();
        if ($target_result->num_rows > 0) {
            $final_church_id = $target_result->fetch_assoc()['church_id'];
        }
        log_debug("Payment attributed to target member ID: $target_member_id");
    } elseif ($payer_member_id) {
        $final_member_id = $payer_member_id;
        // Get church_id for payer member
        $payer_stmt = $conn->prepare('SELECT church_id FROM members WHERE id = ? AND status = "active"');
        $payer_stmt->bind_param('i', $payer_member_id);
        $payer_stmt->execute();
        $payer_result = $payer_stmt->get_result();
        if ($payer_result->num_rows > 0) {
            $final_church_id = $payer_result->fetch_assoc()['church_id'];
        }
        log_debug("Payment attributed to payer member ID: $payer_member_id");
    } elseif ($member_id) {
        $final_member_id = $member_id;
        $final_church_id = $church_id;
        log_debug("Payment attributed to phone lookup member ID: $member_id");
    }
    
    // Step 4: Record payment
    $paymentModel = new Payment();
    
    if ($final_member_id) {
        // Get payment type name for description
        $payment_type_name = 'Payment';
        $type_stmt = $conn->prepare('SELECT name FROM payment_types WHERE id = ?');
        $type_stmt->bind_param('i', $payment_type_id);
        $type_stmt->execute();
        $type_result = $type_stmt->get_result();
        if ($type_result->num_rows > 0) {
            $payment_type_name = $type_result->fetch_assoc()['name'];
        }
        
        // Format description like Hubtel payments: "Payment for [period] [type]"
        $formatted_description = "Payment for " . ($payment_period_description ?: date('F Y')) . " " . $payment_type_name;
        
        // Member identified - record as regular payment
        $payment_data = [
            'member_id' => $final_member_id,
            'amount' => floatval($amount),
            'description' => $formatted_description,
            'payment_date' => $transaction_date,
            'payment_period' => $payment_period,
            'payment_period_description' => $payment_period_description,
            'client_reference' => $reference,
            'status' => $payment_status,
            'church_id' => $final_church_id,
            'payment_type_id' => $payment_type_id,
            'recorded_by' => 'USSD',
            'mode' => 'Mobile Money'
        ];
        
        log_debug('Recording payment for identified member: '.json_encode($payment_data));
        $result = $paymentModel->add($conn, $payment_data);
        
        if ($result) {
            log_debug("Payment recorded successfully with ID: $result");
            
            // Send SMS notification for USSD payment
            require_once __DIR__.'/../includes/sms.php';
            $order_info = $data['OrderInfo'] ?? [];
            $customer_phone = $order_info['CustomerMobileNumber'] ?? '';
            $payment_info = $order_info['Payment'] ?? null;
            $formatted_amount = isset($payment_info['AmountAfterCharges']) ? number_format(floatval($payment_info['AmountAfterCharges']), 2) : number_format(floatval($amount), 2);
            
            $full_name = '';
            if (!empty($order_info['CustomerName'])) {
                $full_name = $order_info['CustomerName'];
            } else {
                $full_name = $customer_phone;
            }
            
            log_debug("SMS check: phone=$customer_phone, amount=$formatted_amount, status=".($order_info['Status'] ?? 'none'));
            if (!empty($customer_phone) && !empty($formatted_amount) && strtolower($order_info['Status'] ?? '') === 'paid') {
                log_debug("SMS conditions met, proceeding with SMS logic");
                // Format description as in manual payment: e.g., 'Harvest - September 2025'
                $desc_formatted = $donation_type;
                if (!empty($payment_period_description)) {
                    $desc_formatted .= " - $payment_period_description";
                }
                
                // If paying for another member, include 'on behalf of' in payer's SMS
                $payer_sms_msg = "Hello $full_name, your payment of $formatted_amount GHS for $desc_formatted has been received by Freeman Methodist Church. Thank you!";
                if (!empty($target_member_id) && $target_member_id != $payer_member_id) {
                    // Lookup target name
                    $target_name = '';
                    $target_stmt = $conn->prepare('SELECT CONCAT(first_name, " ", last_name) as full_name FROM members WHERE id = ? AND status = "active"');
                    $target_stmt->bind_param('i', $target_member_id);
                    $target_stmt->execute();
                    $target_result = $target_stmt->get_result();
                    if ($target_row = $target_result->fetch_assoc()) {
                        $target_name = $target_row['full_name'];
                    }
                    $target_stmt->close();
                    if ($target_name) {
                        $payer_sms_msg = "Hello $full_name, your payment of $formatted_amount GHS for $desc_formatted on behalf of $target_name has been received by Freeman Methodist Church. Thank you!";
                    }
                }
                
                // Only send payer SMS if payer and target are different
                $send_payer_sms = true;
                if (!empty($target_member_id)) {
                    $actual_payer_id = !empty($payer_member_id) ? $payer_member_id : $member_id;
                    if ($target_member_id == $actual_payer_id) {
                        $send_payer_sms = false;
                    }
                }
                if ($send_payer_sms) {
                    log_debug("Sending payer SMS to: $customer_phone");
                    log_sms($customer_phone, $payer_sms_msg, null, 'ussd_payment');
                } else {
                    log_debug("Skipping payer SMS (same as target)");
                }
                
                // Send to target member if different and valid
                if (!empty($target_member_id)) {
                    $target_stmt = $conn->prepare('SELECT phone, CONCAT(first_name, " ", last_name) as full_name FROM members WHERE id = ? AND status = "active"');
                    $target_stmt->bind_param('i', $target_member_id);
                    $target_stmt->execute();
                    $target_result = $target_stmt->get_result();
                    if ($target_row = $target_result->fetch_assoc()) {
                        $target_phone = $target_row['phone'];
                        $target_name = $target_row['full_name'];
                        if (!empty($target_phone) && $target_phone !== $customer_phone) {
                            log_debug("Sending target SMS to: $target_phone");
                            
                            // Get payer's actual name for target SMS
                            $payer_name = $full_name; // Default to phone/customer name
                            if (!empty($payer_member_id)) {
                                $payer_stmt = $conn->prepare('SELECT CONCAT(first_name, " ", last_name) as full_name FROM members WHERE id = ? AND status = "active"');
                                $payer_stmt->bind_param('i', $payer_member_id);
                                $payer_stmt->execute();
                                $payer_result = $payer_stmt->get_result();
                                if ($payer_row = $payer_result->fetch_assoc()) {
                                    $payer_name = $payer_row['full_name'];
                                    log_debug("Payer name found: $payer_name");
                                }
                                $payer_stmt->close();
                            } else {
                                log_debug("No payer member ID, using customer info: $payer_name");
                            }
                            
                            $target_sms_msg = "Hello $target_name, your payment of $formatted_amount GHS for $desc_formatted by $payer_name has been received by Freeman Methodist Church. Thank you!";
                            // If payment type is HARVEST, append total for year
                            if (strtolower($donation_type) === 'harvest' && !empty($payment_period)) {
                                $harvest_year = date('Y', strtotime($payment_period));
                                $harvest_total = 0;
                                $start_date = "$harvest_year-01-01";
                                $end_date = "$harvest_year-12-31";
                                $harvest_stmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE member_id = ? AND payment_type_id = ? AND payment_date >= ? AND payment_date <= ? AND ((reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL))');
                                $harvest_stmt->bind_param('iiss', $target_member_id, $payment_type_id, $start_date, $end_date);
                                $harvest_stmt->execute();
                                $harvest_result = $harvest_stmt->get_result();
                                if ($harvest_row = $harvest_result->fetch_assoc()) {
                                    $harvest_total = floatval($harvest_row['total']);
                                }
                                $harvest_stmt->close();
                                log_debug("Raw harvest total from DB: $harvest_total, payment_type_id: $payment_type_id, target_member_id: $target_member_id, harvest_year: $harvest_year");
                                $harvest_total = number_format($harvest_total, 2);
                                $target_sms_msg .= " Your Total Harvest amount for the year $harvest_year is GHS $harvest_total.";
                                log_debug("Harvest total for year $harvest_year: GHS $harvest_total");
                            }
                            log_sms($target_phone, $target_sms_msg, null, 'ussd_payment_target');
                        } else {
                            log_debug("Skipping target SMS - same phone as payer or no target phone");
                        }
                    }
                    $target_stmt->close();
                } else {
                    log_debug("No target member ID found");
                }
            } else {
                log_debug("SMS conditions not met, skipping SMS");
            }
            
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
    
    // SMS will be sent after payment is recorded
    // Send callback confirmation to Hubtel
    $callback_payload = [
        'SessionId' => $session_id,
        'OrderId' => $order_id,
        'ServiceStatus' => 'success',
        'MetaData' => null
    ];
    
   // $callback_url = 'http://gs-callback.hubtel.com:9055/callback';
    $callback_url = 'https://gs-callback.hubtel.com/callback';
   // $callback_url = 'https://webhook.site/0c2ddd29-1658-4a48-abfc-21f2d038e79a';
    $callback_options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($callback_payload),
            'timeout' => 10
        ]
    ];
    
    $callback_context = stream_context_create($callback_options);
    $callback_result = @file_get_contents($callback_url, false, $callback_context);
    
    if ($callback_result !== false) {
        log_debug('Hubtel callback sent successfully: ' . json_encode($callback_payload));
    } else {
        log_debug('Failed to send Hubtel callback: ' . json_encode($callback_payload));
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
