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
    
    // Handle different session states
    switch ($type) {
        case 'Initiation':
            // Welcome message
            if ($member) {
                $response = [
                    'SessionId' => $session_id,
                    'Type' => 'response',
                    'Message' => "Welcome {$member['full_name']} (CRN: {$member['crn']}) to Freeman Methodist Church Donations\n\n1. General Offering\n2. Tithe\n3. Harvest\n4. Building Fund\n5. Other\n\nSelect donation type:",
                    'Label' => 'Select Donation Type',
                    'ClientState' => 'menu_' . $member['id'],
                    'DataType' => 'input',
                    'FieldType' => 'text'
                ];
            } else {
                $response = [
                    'SessionId' => $session_id,
                    'Type' => 'response',
                    'Message' => "Welcome to Freeman Methodist Church Donations\n\nYour phone number is not registered. Your donation will be recorded for manual assignment.\n\n1. General Offering\n2. Tithe\n3. Harvest\n4. Building Fund\n5. Other\n\nSelect donation type:",
                    'Label' => 'Select Donation Type',
                    'ClientState' => 'menu_unmatched',
                    'DataType' => 'input',
                    'FieldType' => 'text'
                ];
            }
            break;
            
        case 'Response':
            // Extract member ID from client state if present
            $member_id_from_state = null;
            if (preg_match('/^menu_(\d+)$/', $client_state, $matches)) {
                $member_id_from_state = $matches[1];
            }
            
            switch (true) {
                case ($client_state === 'menu' || $client_state === 'menu_unmatched' || str_starts_with($client_state, 'menu_')):
                    // User selected donation type
                    $donation_types = [
                        '1' => 'General Offering',
                        '2' => 'Tithe',
                        '3' => 'Harvest',
                        '4' => 'Building Fund',
                        '5' => 'Other'
                    ];
                    
                    if (isset($donation_types[$message])) {
                        $selected_type = $donation_types[$message];
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'response',
                            'Message' => "You selected: $selected_type\n\nEnter amount to donate (GHS):",
                            'Label' => 'Enter Amount',
                            'ClientState' => "amount_$message",
                            'DataType' => 'input',
                            'FieldType' => 'decimal'
                        ];
                    } else {
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'response',
                            'Message' => "Invalid selection. Please try again.\n\n1. General Offering\n2. Tithe\n3. Harvest\n4. Building Fund\n5. Other\n\nSelect donation type:",
                            'Label' => 'Select Donation Type',
                            'ClientState' => 'menu',
                            'DataType' => 'input',
                            'FieldType' => 'text'
                        ];
                    }
                    break;
                    
                case (str_starts_with($client_state, 'amount_') ? $client_state : ''):
                    // User entered amount
                    $amount = floatval($message);
                    if ($amount > 0) {
                        $type_num = substr($client_state, 7);
                        $donation_types = [
                            '1' => 'General Offering',
                            '2' => 'Tithe',
                            '3' => 'Harvest',
                            '4' => 'Building Fund',
                            '5' => 'Other'
                        ];
                        $selected_type = $donation_types[$type_num] ?? 'Donation';
                        
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'AddToCart',
                            'Message' => "Thank you! You will receive a payment prompt for GHS " . number_format($amount, 2) . " for $selected_type.",
                            'Item' => [
                                'ItemName' => $selected_type,
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
                            'Message' => "Invalid amount. Please enter a valid amount (minimum GHS 1.00):",
                            'Label' => 'Enter Amount',
                            'ClientState' => $client_state,
                            'DataType' => 'input',
                            'FieldType' => 'decimal'
                        ];
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
