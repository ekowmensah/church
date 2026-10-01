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
require_once __DIR__.'/../services/PaymentGatewayCallbackService.php';
require_once __DIR__.'/../services/OnlinePaymentApprovalService.php';
require_once __DIR__.'/../includes/payment_sms_template.php';
require_once __DIR__.'/../helpers/hubtel_status.php';
require_once __DIR__.'/../helpers/hubtel_ussd_amount_helper.php';

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

function fetch_active_member_profile($conn, $member_id) {
    $stmt = $conn->prepare("
        SELECT
            phone,
            TRIM(CONCAT_WS(' ',
                NULLIF(TRIM(first_name), ''),
                NULLIF(TRIM(middle_name), ''),
                NULLIF(TRIM(last_name), '')
            )) as full_name,
            crn,
            church_id
        FROM members
        WHERE id = ?
          AND status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $member ?: null;
}

function fetch_active_sunday_school_profile($conn, $child_id) {
    $stmt = $conn->prepare("
        SELECT contact AS phone,
               TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS full_name,
               srn, church_id
          FROM sunday_school
         WHERE id = ? AND transferred_to_member_id IS NULL
         LIMIT 1
    ");
    $stmt->bind_param('i', $child_id);
    $stmt->execute();
    $child = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $child ?: null;
}

function fetch_active_church_name($conn, $church_id) {
    if (empty($church_id)) {
        return 'Freeman Methodist Church - KM';
    }

    $stmt = $conn->prepare('SELECT name FROM churches WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $church_id);
    $stmt->execute();
    $church = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $church['name'] ?? 'Freeman Methodist Church - KM';
}

function phones_match_shortcode($left, $right) {
    $left = preg_replace('/\D+/', '', (string) $left);
    $right = preg_replace('/\D+/', '', (string) $right);

    if ($left === '' || $right === '') {
        return false;
    }

    return substr($left, -9) === substr($right, -9);
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
$separated_amounts = hubtel_ussd_separate_amounts($order_info);
$amount = $separated_amounts['contribution_amount'];
$customer_paid_amount = $separated_amounts['customer_paid_amount'];
$gateway_charge_amount = $separated_amounts['gateway_charge_amount'];
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

log_debug("Processed payment data - Contribution: $amount, Customer paid: "
    . ($customer_paid_amount ?? 'unknown') . ", Gateway charge: $gateway_charge_amount, Phone: $phone, Reference: $reference, Status: $status");

// A Paid + IsSuccessful fulfillment is authoritative and may be posted
// automatically. Failed/uncertain notifications are retained as intents only;
// a later status check can move them into the manual approval queue.
$is_definitive_success = strtolower(trim((string) $status)) === 'paid' && (bool) $is_successful;
$payment_status = $is_definitive_success ? 'Completed' : (string) $status;
if (!$is_definitive_success) {
    log_debug("USSD fulfillment is not definitively successful. Status: $status, IsSuccessful: " . ($is_successful ? 'true' : 'false'));
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
    $target_sunday_school_id = null;
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
                if (preg_match('/Sunday School ID:\s*(\d+)/', $member_info, $child_matches)) {
                    $target_sunday_school_id = intval($child_matches[1]);
                    log_debug("Sunday School payment - child ID: $target_sunday_school_id");
                } elseif (preg_match('/Member ID:\s*(\d+)/', $member_info, $member_matches)) {
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
    $final_sunday_school_id = null;
    $final_church_id = null;
    
    // Priority: target_member_id > payer_member_id > member_id (from phone lookup)
    if ($target_sunday_school_id) {
        $final_sunday_school_id = $target_sunday_school_id;
        $target_stmt = $conn->prepare('SELECT church_id FROM sunday_school WHERE id = ? AND transferred_to_member_id IS NULL');
        $target_stmt->bind_param('i', $target_sunday_school_id);
        $target_stmt->execute();
        $target_result = $target_stmt->get_result();
        if ($target_result->num_rows > 0) {
            $final_church_id = $target_result->fetch_assoc()['church_id'];
        } else {
            throw new RuntimeException('The selected Sunday School beneficiary is unavailable.');
        }
        log_debug("Payment attributed to Sunday School ID: $target_sunday_school_id");
    } elseif ($target_member_id) {
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
    
    // Step 4: Capture every attributable result. A definitive first success
    // posts immediately; a failed/uncertain result posts nothing, and a later
    // status-confirmed recovery remains pending for authorized approval.
    if ($final_member_id || $final_sunday_school_id) {
        $payment_type_name = 'Payment';
        $type_stmt = $conn->prepare('SELECT name FROM payment_types WHERE id = ?');
        $type_stmt->bind_param('i', $payment_type_id);
        $type_stmt->execute();
        $type_result = $type_stmt->get_result();
        if ($type_result->num_rows > 0) {
            $payment_type_name = $type_result->fetch_assoc()['name'];
        }
        $type_stmt->close();
        $formatted_description = "Payment for " . ($payment_period_description ?: date('F Y')) . " " . $payment_type_name;
        // Hubtel's shortcode Service Fulfillment notification is the
        // server-to-server confirmation for this channel. Its OrderId is not
        // an online-checkout clientReference and therefore cannot be used as a
        // hard dependency on the transaction-status endpoint. Doing so caused
        // valid Paid + IsSuccessful fulfillments to remain unposted whenever
        // that separate endpoint rejected the lookup.
        //
        // Only the definitive success combination is trusted here. Every
        // other result remains unverified and can become payable only through
        // the existing status-check plus authorized-approval workflow.
        $verificationStatus = $is_definitive_success ? 'verified' : 'not_checked';
        $gatewayService = new PaymentGatewayCallbackService($conn);
        $capture = $gatewayService->record([
            'client_reference' => $reference,
            'transaction_id' => $order_id,
            'status' => $payment_status,
            'amount' => (float) $amount,
            'description' => $formatted_description,
            'customer_name' => $order_info['CustomerName'] ?? $phone,
            'customer_phone' => $phone,
            'member_id' => $final_member_id,
            'sundayschool_id' => $final_sunday_school_id,
            'church_id' => $final_church_id,
            'payment_type_id' => $payment_type_id,
            'payment_period' => $payment_period,
            'payment_period_description' => $payment_period_description,
            'payment_source' => 'ussd',
            'gateway_verification_status' => $verificationStatus,
            'gateway_customer_paid_amount' => $customer_paid_amount,
            'gateway_charge_amount' => $gateway_charge_amount,
            'raw_payload' => $raw_input,
        ]);
        if ($is_definitive_success
            && ($capture['previous_status'] ?? null) !== 'Failed'
            && ($capture['confirmation_source'] ?? null) === 'gateway_callback'
            && ($capture['gateway_verification_status'] ?? null) === 'verified') {
            // The shortcode has beneficiary/payer context needed by the
            // established USSD templates, so suppress the service's generic
            // gateway receipt and send the proper messages below.
            $postingService = new OnlinePaymentApprovalService($conn, 0, true, false);
            $posting = $postingService->autoPostSuccessfulUssd(
                (int) $capture['intent_id'],
                'Automatically posted from a definitive Hubtel shortcode fulfillment.'
            );
            log_debug('USSD payment automatically posted: ' . json_encode($posting));

            if (empty($posting['already_posted']) && !empty($posting['payment_ids'])) {
                require_once __DIR__.'/../includes/sms.php';
                $customer_phone = $phone;
                $payer_name = normalize_payment_sms_value($order_info['CustomerName'] ?? $customer_phone);
                $payer_profile = $payer_member_id ? fetch_active_member_profile($conn, (int) $payer_member_id) : null;
                if ($payer_profile && !empty($payer_profile['full_name'])) {
                    $payer_name = $payer_profile['full_name'];
                }

                $beneficiary_profile = $final_sunday_school_id
                    ? fetch_active_sunday_school_profile($conn, (int) $final_sunday_school_id)
                    : fetch_active_member_profile($conn, (int) $final_member_id);
                $beneficiary_name = $beneficiary_profile['full_name'] ?? $payer_name;
                $beneficiary_phone = $beneficiary_profile['phone'] ?? $customer_phone;
                $church_name = fetch_active_church_name($conn, $final_church_id);
                $show_by_sender = !phones_match_shortcode($customer_phone, $beneficiary_phone);
                $sender_name_for_message = $show_by_sender ? $payer_name : '';

                $harvest_year = null;
                $harvest_total = null;
                if ($final_member_id && is_harvest_payment_type($payment_type_name)) {
                    $harvest_year = get_payment_period_year($payment_period, $payment_period_description, $transaction_date);
                    $harvest_total = get_member_yearly_harvest_total(
                        $conn,
                        (int) $final_member_id,
                        $harvest_year,
                        (int) $payment_type_id
                    );
                }

                if (!empty($beneficiary_phone)) {
                    $beneficiary_sms = build_hubtel_ussd_member_payment_sms(
                        $beneficiary_name,
                        $amount,
                        $payment_period_description,
                        $payment_type_name,
                        $sender_name_for_message,
                        $church_name,
                        $harvest_year,
                        $harvest_total,
                        $payment_period,
                        $transaction_date
                    );
                    log_sms(
                        $beneficiary_phone,
                        $beneficiary_sms,
                        (int) $posting['payment_ids'][0],
                        'ussd_payment_target'
                    );
                }

                if ($show_by_sender && !empty($customer_phone)) {
                    $payer_sms = build_hubtel_ussd_payer_confirmation_sms(
                        $payer_name,
                        $amount,
                        $payment_period_description,
                        $payment_type_name,
                        $beneficiary_name,
                        $church_name,
                        $payment_period,
                        $transaction_date
                    );
                    log_sms(
                        $customer_phone,
                        $payer_sms,
                        (int) $posting['payment_ids'][0],
                        'ussd_payment'
                    );
                }
            }
        } else {
            log_debug('USSD intent retained for status checking or authorized approval: ' . json_encode($capture));
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
    
    if (!$is_definitive_success) {
        http_response_code(200);
        echo json_encode([
            'status' => 'pending_verification',
            'message' => 'USSD status retained for verification; no payment was posted.'
        ]);
        exit;
    }

    // The posting service sends receipts only after the payment is committed.
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
