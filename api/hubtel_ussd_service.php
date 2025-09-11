<?php
/**
 * Hubtel USSD Service Interaction Handler
 * 
 * This endpoint handles USSD service interactions for church donations
 * and guides users through the payment process.
 * 
 * Expected request format from Hubtel:
 * {
 *   "Type": "Initiation|Response|Timeout",
 *   "Mobile": "233200585542",
 *   "SessionId": "3c796dac28174f739de4262d08409c51",
 *   "ServiceCode": "713",
 *   "Message": "*713#",
 *   "Operator": "vodafone",
 *   "Sequence": 1,
 *   "ClientState": "",
 *   "Platform": "USSD"
 * }
 */

header('Content-Type: application/json');

require_once __DIR__.'/../config/config.php';

// Set up logging
$debug_log = __DIR__.'/../logs/ussd_service_debug.log';
$raw_log = __DIR__.'/../logs/ussd_service_raw.log';

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
log_debug('USSD service called');

// Parse and validate input
$data = json_decode($raw_input, true);
log_debug('USSD data: '.json_encode($data));

if (!$data) {
    log_debug('Invalid JSON data received');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Extract USSD session data
$type = $data['Type'] ?? '';
$mobile = $data['Mobile'] ?? '';
$session_id = $data['SessionId'] ?? '';
$service_code = $data['ServiceCode'] ?? '';
$message = $data['Message'] ?? '';
$operator = $data['Operator'] ?? '';
$sequence = $data['Sequence'] ?? 1;
$client_state = $data['ClientState'] ?? '';
$platform = $data['Platform'] ?? 'USSD';

log_debug("USSD Session - Type: $type, Mobile: $mobile, Sequence: $sequence, State: $client_state, Message: $message");

// Normalize phone number
$phone = preg_replace('/^\+233/', '0', $mobile);
$phone = preg_replace('/^233/', '0', $phone);
if (strlen($phone) === 9 && !str_starts_with($phone, '0')) {
    $phone = '0' . $phone;
}

try {
    // Check if user is a registered member by phone number
    $member_stmt = $conn->prepare('SELECT id, CONCAT(first_name, " ", last_name) as full_name, crn, phone FROM members WHERE phone = ? AND status = "active" LIMIT 1');
    $member_stmt->bind_param('s', $phone);
    $member_stmt->execute();
    $member_result = $member_stmt->get_result();
    $member = $member_result->fetch_assoc();
    
    if ($member) {
        log_debug("Member found by phone: {$member['full_name']} ({$member['crn']})");
    } else {
        log_debug("No member found for phone: $phone");
    }
    
    // Get payment types from database
    $payment_types = [];
    $types_result = $conn->query("SELECT id, name FROM payment_types WHERE active = 1 ORDER BY name ASC");
    if (!$types_result) {
        log_debug("Failed to query payment_types: " . $conn->error);
        throw new Exception("Database query failed for payment_types");
    }
    
    while ($row = $types_result->fetch_assoc()) {
        $payment_types[] = $row;
    }
    log_debug("Loaded " . count($payment_types) . " payment types");
    
    // Build payment types menu
    $payment_menu = "";
    foreach ($payment_types as $index => $type) {
        $payment_menu .= ($index + 1) . ". " . $type['name'] . "\n";
    }
    log_debug("Built payment menu with " . strlen($payment_menu) . " characters");
    
    // Handle different session states
    log_debug("Processing USSD type: $type");
    switch ($type) {
        case 'Initiation':
            log_debug("Handling Initiation case");
            // Welcome message
            if ($member) {
                log_debug("Building response for registered member: {$member['full_name']}");
                $response = [
                    'SessionId' => $session_id,
                    'Type' => 'response',
                    'Message' => "Welcome {$member['full_name']} (CRN: {$member['crn']}) to Freeman Methodist Church Payments\n\nWho are you paying for?\n1. Myself\n2. Another member\n\nSelect option:",
                    'Label' => 'Payment For',
                    'ClientState' => 'payment_for_' . $member['id'],
                    'DataType' => 'input',
                    'FieldType' => 'text'
                ];
                log_debug("Response built successfully for registered member");
            } else {
                log_debug("Building response for unregistered user");
                $response = [
                    'SessionId' => $session_id,
                    'Type' => 'response',
                    'Message' => "Welcome to Freeman Methodist Church Payments\n\nYour phone number is not registered.\n\nWho are you paying for?\n1. Myself (unregistered)\n2. A church member (enter CRN)\n\nSelect option:",
                    'Label' => 'Payment For',
                    'ClientState' => 'payment_for_unregistered',
                    'DataType' => 'input',
                    'FieldType' => 'text'
                ];
                log_debug("Response built successfully for unregistered user");
            }
            log_debug("Initiation case completed successfully");
            break;
            
        case 'Response':
            // Extract member ID from client state if present
            $member_id_from_state = null;
            if (preg_match('/^menu_(\d+)$/', $client_state, $matches)) {
                $member_id_from_state = $matches[1];
            }
            
            switch (true) {
                case (str_starts_with($client_state, 'payment_for_')):
                    if ($client_state === 'payment_for_unregistered') {
                        // Unregistered user selecting who to pay for
                        if ($message === '1') {
                            // Paying for themselves (unregistered) - go to payment types
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Payment Types:\n\n" . $payment_menu . "\nSelect donation type:",
                                'Label' => 'Select Donation Type',
                                'ClientState' => "menu_unmatched",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        } elseif ($message === '2') {
                            // Paying for a church member - ask for CRN
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Enter the CRN of the church member you want to pay for:",
                                'Label' => 'Enter CRN',
                                'ClientState' => "crn_input_unregistered",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        } else {
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Invalid selection. Please try again.\n\nWho are you paying for?\n1. Myself (unregistered)\n2. A church member (enter CRN)\n\nSelect option:",
                                'Label' => 'Payment For',
                                'ClientState' => 'payment_for_unregistered',
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        }
                    } else {
                        // Registered member selecting who to pay for
                        $member_id = substr($client_state, 12);
                        if ($message === '1') {
                            // Paying for themselves - go directly to payment types
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Payment Types:\n\n" . $payment_menu . "\nSelect donation type:",
                                'Label' => 'Select Donation Type',
                                'ClientState' => "menu_self_$member_id",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        } elseif ($message === '2') {
                            // Paying for another member - ask for CRN
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Enter the CRN of the member you want to pay for:",
                                'Label' => 'Enter CRN',
                                'ClientState' => "crn_input_$member_id",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        } else {
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Invalid selection. Please try again.\n\nWho are you paying for?\n1. Myself\n2. Another member\n\nSelect option:",
                                'Label' => 'Payment For',
                                'ClientState' => "payment_for_$member_id",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        }
                    }
                    break;
                    
                case (str_starts_with($client_state, 'crn_input_')):
                    // User entered CRN for another member
                    $context = substr($client_state, 10);
                    $crn = strtoupper(trim($message));
                    
                    // Validate CRN exists
                    $crn_stmt = $conn->prepare("SELECT id, CONCAT(first_name, ' ', last_name) as full_name, crn FROM members WHERE crn = ? AND status = 'active'");
                    $crn_stmt->bind_param("s", $crn);
                    $crn_stmt->execute();
                    $crn_result = $crn_stmt->get_result();
                    $target_member = $crn_result->fetch_assoc();
                    
                    if ($target_member) {
                        if ($context === 'unregistered') {
                            // Unregistered user paying for a member
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Payment for: {$target_member['full_name']} ({$target_member['crn']})\n\n" . $payment_menu . "\nSelect donation type:",
                                'Label' => 'Select Donation Type',
                                'ClientState' => "menu_unregistered_for_{$target_member['id']}",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        } else {
                            // Registered member paying for another member
                            $payer_id = $context;
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Payment for: {$target_member['full_name']} ({$target_member['crn']})\n\n" . $payment_menu . "\nSelect donation type:",
                                'Label' => 'Select Donation Type',
                                'ClientState' => "menu_other_{$payer_id}_{$target_member['id']}",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        }
                    } else {
                        if ($context === 'unregistered') {
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "CRN '$crn' not found. Please try again.\n\nEnter the CRN of the church member you want to pay for:",
                                'Label' => 'Enter CRN',
                                'ClientState' => "crn_input_unregistered",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        } else {
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "CRN '$crn' not found. Please try again.\n\nEnter the CRN of the member you want to pay for:",
                                'Label' => 'Enter CRN',
                                'ClientState' => "crn_input_$context",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        }
                    }
                    break;
                
                case ($client_state === 'menu' || $client_state === 'menu_unmatched' || str_starts_with($client_state, 'menu_')):
                    // User selected donation type
                    $selection = intval($message);
                    
                    if ($selection >= 1 && $selection <= count($payment_types)) {
                        $selected_type = $payment_types[$selection - 1];
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'response',
                            'Message' => "You selected: {$selected_type['name']}\n\nEnter amount to donate (GHS):",
                            'Label' => 'Enter Amount',
                            'ClientState' => "amount_{$selected_type['id']}_" . str_replace('menu_', '', $client_state),
                            'DataType' => 'input',
                            'FieldType' => 'decimal'
                        ];
                    } else {
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'response',
                            'Message' => "Invalid selection. Please try again.\n\n" . $payment_menu . "\nSelect donation type:",
                            'Label' => 'Select Donation Type',
                            'ClientState' => $client_state,
                            'DataType' => 'input',
                            'FieldType' => 'text'
                        ];
                    }
                    break;
                    
                case (str_starts_with($client_state, 'amount_') ? $client_state : ''):
                    // User entered amount
                    $amount = floatval($message);
                    if ($amount > 0) {
                        // Parse client state: amount_{payment_type_id}_{context}
                        $parts = explode('_', $client_state);
                        $payment_type_id = $parts[1];
                        $context = isset($parts[2]) ? $parts[2] : '';
                        
                        // Find payment type name
                        $selected_type_name = 'Donation';
                        foreach ($payment_types as $type) {
                            if ($type['id'] == $payment_type_id) {
                                $selected_type_name = $type['name'];
                                break;
                            }
                        }
                        
                        // Check if this is for self payment (registered member)
                        if (str_starts_with($context, 'self_')) {
                            // Skip CRN input for registered members paying for themselves
                            $member_id = substr($context, 5);
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Amount: GHS " . number_format($amount, 2) . " for $selected_type_name\n\nConfirm payment?\n1. Yes, proceed\n2. No, cancel",
                                'Label' => 'Confirm Payment',
                                'ClientState' => "confirm_{$payment_type_id}_{$amount}_self_{$member_id}",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        } else {
                            // Ask for CRN for unregistered users or other member payments
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Amount: GHS " . number_format($amount, 2) . " for $selected_type_name\n\nConfirm payment?\n1. Yes, proceed\n2. No, cancel",
                                'Label' => 'Confirm Payment',
                                'ClientState' => "confirm_{$payment_type_id}_{$amount}_{$context}",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        }
                    } else {
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'response',
                            'Message' => "Invalid amount. Please enter a valid amount (minimum GHS 1.00):",
                            'Label' => 'Enter Amount',
                            'ClientState' => $client_state,
                            'DataType' => 'input',
                            'FieldType' => 'decimal'
                        ];
                    }
                    break;
                    
                case (str_starts_with($client_state, 'confirm_') ? $client_state : ''):
                    // User confirmed payment
                    if ($message === '1') {
                        // Parse client state: confirm_{payment_type_id}_{amount}_{context}
                        $parts = explode('_', $client_state);
                        $payment_type_id = $parts[1];
                        $amount = floatval($parts[2]);
                        $context = isset($parts[3]) ? $parts[3] : '';
                        
                        // Find payment type name
                        $selected_type_name = 'Donation';
                        foreach ($payment_types as $type) {
                            if ($type['id'] == $payment_type_id) {
                                $selected_type_name = $type['name'];
                                break;
                            }
                        }
                        
                        // Determine member info based on context
                        $member_info = '';
                        if (str_starts_with($context, 'self_')) {
                            $member_id = substr($context, 5);
                            $member_info = "Member ID: $member_id";
                        } elseif (str_starts_with($context, 'other_')) {
                            $parts_context = explode('_', $context);
                            $payer_id = $parts_context[1];
                            $target_id = $parts_context[2];
                            $member_info = "Payer ID: $payer_id, Target ID: $target_id";
                        } elseif (str_starts_with($context, 'unregistered_for_')) {
                            $target_id = substr($context, 17);
                            $member_info = "Phone: $phone (unregistered), Target ID: $target_id";
                        } elseif ($context === 'unmatched') {
                            $member_info = "Phone: $phone (unregistered)";
                        }
                        
                        $item_description = "$selected_type_name - $member_info";
                        
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'AddToCart',
                            'Message' => "Thank you! You will receive a payment prompt for GHS " . number_format($amount, 2) . " for $selected_type_name.",
                            'Item' => [
                                'ItemName' => $item_description,
                                'Qty' => 1,
                                'Price' => $amount
                            ],
                            'DataType' => 'input',
                            'FieldType' => 'text'
                        ];
                    } else {
                        // User cancelled
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'response',
                            'Message' => "Payment cancelled. Thank you for using Freeman Methodist Church USSD service.\n\nDial *713*4# to start again.",
                            'Label' => 'Payment Cancelled',
                            'ClientState' => 'cancelled',
                            'DataType' => 'input',
                            'FieldType' => 'text'
                        ];
                    }
                    break;
                    
                case (str_starts_with($client_state, 'crn_') ? $client_state : ''):
                    // Legacy CRN handling (keeping for backward compatibility)
                    $parts = explode('_', $client_state);
                    if (count($parts) >= 3) {
                        $type_num = $parts[1];
                        $amount = floatval($parts[2]);
                        $crn = trim($message);
                        
                        if (!empty($crn)) {
                            $item_description = "Legacy Payment - CRN: $crn";
                            
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'AddToCart',
                                'Message' => "Thank you! You will receive a payment prompt for GHS " . number_format($amount, 2) . " (CRN: $crn).",
                                'Item' => [
                                    'ItemName' => $item_description,
                                    'Qty' => 1,
                                    'Price' => $amount
                                ],
                                'Label' => 'Payment Processing',
                                'DataType' => 'display',
                                'FieldType' => 'text'
                            ];
                        } else {
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "CRN is required. Please enter your CRN (Church Registration Number):",
                                'Label' => 'Enter CRN',
                                'ClientState' => $client_state,
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        }
                    }
                    break;
                    
                default:
                    // Unknown state - restart
                    $response = [
                        'SessionId' => $session_id,
                        'Type' => 'release',
                        'Message' => "Session error. Please try again.",
                        'Label' => 'Session Error',
                        'DataType' => 'display',
                        'FieldType' => 'text'
                    ];
                    break;
            }
            break;
            
        case 'Timeout':
            // Session timed out
            $response = [
                'SessionId' => $session_id,
                'Type' => 'release',
                'Message' => "Session timed out. Please dial the code again to make a donation.",
                'Label' => 'Session Timeout',
                'DataType' => 'display',
                'FieldType' => 'text'
            ];
            break;
            
        default:
            // Unknown type
            log_debug("Unknown USSD type received: '$type' - falling to default case");
            $response = [
                'SessionId' => $session_id,
                'Type' => 'release',
                'Message' => "Service unavailable. Please try again later.",
                'Label' => 'Service Error',
                'DataType' => 'display',
                'FieldType' => 'text'
            ];
            break;
    }
    
    log_debug('USSD Response: '.json_encode($response));
    echo json_encode($response);
    
} catch (Exception $e) {
    log_debug('Error processing USSD request: '.$e->getMessage());
    
    $error_response = [
        'SessionId' => $session_id,
        'Type' => 'release',
        'Message' => "Service temporarily unavailable. Please try again later.",
        'Label' => 'Service Error',
        'DataType' => 'display',
        'FieldType' => 'text'
    ];
    
    echo json_encode($error_response);
}
?>
