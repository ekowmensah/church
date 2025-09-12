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

// Ensure log directory exists
if (!is_dir(__DIR__.'/../logs')) {
    mkdir(__DIR__.'/../logs', 0755, true);
}

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
    $types_result = $conn->query("SELECT id, name FROM payment_types WHERE active = 1 ORDER BY CASE 
        WHEN UPPER(name) = 'TITHE' THEN 1
        WHEN UPPER(name) = 'WELFARE' THEN 2
        WHEN UPPER(name) = 'HARVEST' THEN 3
        WHEN UPPER(name) = 'OFFERING' THEN 4
        WHEN UPPER(name) = 'SUNDAY SCHOOL' THEN 5
        WHEN UPPER(name) = 'APPEAL' THEN 6
        ELSE 7
    END, name ASC");
    if (!$types_result) {
        log_debug("Failed to query payment_types: " . $conn->error);
        throw new Exception("Database query failed for payment_types");
    }
    
    while ($row = $types_result->fetch_assoc()) {
        $payment_types[] = $row;
        log_debug("Loaded payment type: {$row['id']} - {$row['name']}");
    }
    log_debug("Total payment types loaded: " . count($payment_types));
    
    // Build paginated payment types menu (max 5 items per page to fit USSD limits)
    function build_payment_menu_page($payment_types, $page = 1, $items_per_page = 4) {
        $total_items = count($payment_types);
        $total_pages = ceil($total_items / $items_per_page);
        $start_index = ($page - 1) * $items_per_page;
        $end_index = min($start_index + $items_per_page, $total_items);
        
        log_debug("Pagination: Total items: $total_items, Page: $page, Items per page: $items_per_page, Total pages: $total_pages, Start: $start_index, End: $end_index");
        
        $menu = "";
        for ($i = $start_index; $i < $end_index; $i++) {
            $menu .= ($i + 1) . ". " . $payment_types[$i]['name'] . "\n";
        }
        
        // Always add navigation options if multiple pages exist
        log_debug("Navigation check: total_pages=$total_pages, current_page=$page, page < total_pages=" . ($page < $total_pages ? 'true' : 'false'));
        if ($total_pages > 1) {
            $menu .= "\n";
            // Always show Next if not on last page
            if ($page < $total_pages) {
                $menu .= "98. Next\n";
                log_debug("Added 'Next' option");
            }
            // Always show Previous if not on first page  
            if ($page > 1) {
                $menu .= "99. Previous\n";
                log_debug("Added 'Previous' option");
            }
        }
        
        return [
            'menu' => $menu,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'has_next' => $page < $total_pages,
            'has_prev' => $page > 1
        ];
    }
    
    // Build paginated payment period menu (4 items per page for exactly 3 pages)
    function build_period_menu_page($page = 1, $items_per_page = 4) {
        // Generate 12 months (current + previous 11)
        $periods = [];
        for ($i = 0; $i < 12; $i++) {
            $date = date('Y-m-01', strtotime("-$i months"));
            $display = date('F Y', strtotime($date));
            $periods[] = ['date' => $date, 'display' => $display];
        }
        
        $total_items = count($periods);
        $total_pages = ceil($total_items / $items_per_page);
        $start_index = ($page - 1) * $items_per_page;
        $end_index = min($start_index + $items_per_page, $total_items);
        
        log_debug("Period Pagination: Total items: $total_items, Page: $page, Items per page: $items_per_page, Total pages: $total_pages, Start: $start_index, End: $end_index");
        
        $menu = "";
        for ($i = $start_index; $i < $end_index; $i++) {
            $menu .= ($i + 1) . ". " . $periods[$i]['display'] . "\n";
        }
        
        // Add navigation options if multiple pages exist
        if ($total_pages > 1) {
            $menu .= "\n";
            if ($page < $total_pages) {
                $menu .= "98. Next\n";
                log_debug("Added 'Next' option for periods");
            }
            if ($page > 1) {
                $menu .= "99. Previous\n";
                log_debug("Added 'Previous' option for periods");
            }
        } else {
            log_debug("Only 1 page, no navigation options added");
        }
        
        log_debug("Built menu with " . ($end_index - $start_index) . " items, has_next: " . ($page < $total_pages ? 'true' : 'false') . ", menu length: " . strlen($menu) . " chars");
        log_debug("Menu content: " . str_replace("\n", "\\n", $menu));
        
        return [
            'menu' => $menu,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'has_next' => $page < $total_pages,
            'has_prev' => $page > 1,
            'periods' => $periods
        ];
    }
    
    $payment_menu_data = build_payment_menu_page($payment_types, 1);
    log_debug("Built paginated payment menu - Page 1 of {$payment_menu_data['total_pages']} with " . strlen($payment_menu_data['menu']) . " characters");
    
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
                    'Message' => "Welcome to Freeman Methodist Church Payments\n\nYour phone number is not registered.\n\nEnter the CRN of the member you want to pay for:",
                    'Label' => 'Enter CRN',
                    'ClientState' => 'crn_input_unregistered',
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
                            $unregistered_menu_data = build_payment_menu_page($payment_types, 1);
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Payment Types (1/{$unregistered_menu_data['total_pages']}):\n\n" . $unregistered_menu_data['menu'] . "Select type:",
                                'Label' => 'Select Payment Type',
                                'ClientState' => "menu_unmatched_page_1",
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
                                'Message' => "Invalid selection. Please try again.\n\nWho are you paying for?\n1. Myself (unregistered)\n2. Member (enter CRN)\n\nSelect option:",
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
                            // Paying for themselves - go to payment types
                            $self_menu_data = build_payment_menu_page($payment_types, 1);
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Payment Types (1/{$self_menu_data['total_pages']}):\n\n" . $self_menu_data['menu'] . "Select type:",
                                'Label' => 'Select Payment Type',
                                'ClientState' => "menu_self_{$member['id']}_page_1",
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
                            $unregistered_for_menu_data = build_payment_menu_page($payment_types, 1);
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "For: {$target_member['full_name']} \n\nPayment Types (1/{$unregistered_for_menu_data['total_pages']}):\n\n" . $unregistered_for_menu_data['menu'] . "Select type:",
                                'Label' => 'Select Payment Type',
                                'ClientState' => "menu_unregistered_for_{$target_member['id']}_page_1",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        } else {
                            // Registered member paying for another member
                            $payer_id = $context;
                            $other_menu_data = build_payment_menu_page($payment_types, 1);
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "For: {$target_member['full_name']} \n\nPayment Types (1/{$other_menu_data['total_pages']}):\n\n" . $other_menu_data['menu'] . "Select type:",
                                'Label' => 'Select Payment Type',
                                'ClientState' => "menu_other_{$payer_id}_{$target_member['id']}_page_1",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        }
                    } else {
                        if ($context === 'unregistered') {
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "CRN '$crn' not found. Please try again.\n\nEnter the CRN of the member you want to pay for:",
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
                    $selection = intval($message);
                    
                    // Handle pagination navigation first
                    if ($selection === 98 || $selection === 99) {
                        // Extract current page and context from client state
                        if (preg_match('/^menu_(.+)_page_(\d+)$/', $client_state, $matches)) {
                            $context = $matches[1];
                            $current_page = intval($matches[2]);
                            
                            if ($selection === 98) {
                                // Next page
                                $new_page = $current_page + 1;
                            } else {
                                // Previous page
                                $new_page = $current_page - 1;
                            }
                            
                            $menu_data = build_payment_menu_page($payment_types, $new_page);
                            
                            // Build appropriate message based on context
                            $message = "Payment Types ({$new_page}/{$menu_data['total_pages']}):\n\n" . $menu_data['menu'] . "Select type:";
                            
                            // Check if this is for a specific member and add member info
                            if (str_starts_with($context, 'unregistered_for_')) {
                                $target_member_id = substr($context, 17);
                                $member_stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) as full_name, crn FROM members WHERE id = ? AND status = 'active'");
                                $member_stmt->bind_param("i", $target_member_id);
                                $member_stmt->execute();
                                $member_result = $member_stmt->get_result();
                                $target_member = $member_result->fetch_assoc();
                                
                                if ($target_member) {
                                    $message = "For: {$target_member['full_name']} \n\nPayment Types ({$new_page}/{$menu_data['total_pages']}):\n\n" . $menu_data['menu'] . "Select type:";
                                }
                            } elseif (str_starts_with($context, 'other_')) {
                                $context_parts = explode('_', $context, 3);
                                $target_member_id = $context_parts[2] ?? null;
                                if ($target_member_id) {
                                    $member_stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) as full_name, crn FROM members WHERE id = ? AND status = 'active'");
                                    $member_stmt->bind_param("i", $target_member_id);
                                    $member_stmt->execute();
                                    $member_result = $member_stmt->get_result();
                                    $target_member = $member_result->fetch_assoc();
                                    
                                    if ($target_member) {
                                        $message = "For: {$target_member['full_name']} \n\nPayment Types ({$new_page}/{$menu_data['total_pages']}):\n\n" . $menu_data['menu'] . "Select type:";
                                    }
                                }
                            }
                            
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => $message,
                                'Label' => 'Select Payment Type',
                                'ClientState' => "menu_{$context}_page_{$new_page}",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                            break;
                        }
                    }
                    
                    // Handle payment type selection
                    if ($selection >= 1 && $selection <= count($payment_types)) {
                        $selected_type = $payment_types[$selection - 1];
                        
                        // Generate paginated payment period options
                        $period_data = build_period_menu_page(1);
                        
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'response',
                            'Message' => "You selected: {$selected_type['name']}\n\nSelect payment period (1/{$period_data['total_pages']}):\n\n" . $period_data['menu'] . "Select period:",
                            'Label' => 'Select Period',
                            'ClientState' => "period_{$selected_type['id']}_" . str_replace('menu_', '', $client_state) . "_page_1",
                            'DataType' => 'input',
                            'FieldType' => 'text'
                        ];
                    } else {
                        // Invalid selection - show current page again
                        $current_page = 1;
                        $context = 'unmatched';
                        
                        // Extract page info if available
                        if (preg_match('/^menu_(.+)_page_(\d+)$/', $client_state, $matches)) {
                            $context = $matches[1];
                            $current_page = intval($matches[2]);
                        }
                        
                        $menu_data = build_payment_menu_page($payment_types, $current_page);
                        
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'response',
                            'Message' => "Invalid selection. Please try again.\n\nPayment Types ({$current_page}/{$menu_data['total_pages']}):\n\n" . $menu_data['menu'] . "Select type:",
                            'Label' => 'Select Payment Type',
                            'ClientState' => $client_state,
                            'DataType' => 'input',
                            'FieldType' => 'text'
                        ];
                    }
                    break;
                    
                case (str_starts_with($client_state, 'period_') ? $client_state : ''):
                    // User selected payment period or navigation
                    $selection = intval($message);
                    
                    // Handle pagination navigation (98 = Next, 99 = Previous)
                    if ($selection === 98 || $selection === 99) {
                        // Extract current page from client state
                        if (preg_match('/^period_(\d+)_(.+)_page_(\d+)$/', $client_state, $matches)) {
                            $payment_type_id = $matches[1];
                            $context = $matches[2];
                            $current_page = intval($matches[3]);
                            
                            if ($selection === 98) {
                                // Next page
                                $new_page = $current_page + 1;
                            } else {
                                // Previous page
                                $new_page = $current_page - 1;
                            }
                            
                            $period_data = build_period_menu_page($new_page);
                            
                            // Get payment type name
                            $selected_type_name = '';
                            foreach ($payment_types as $type) {
                                if ($type['id'] == $payment_type_id) {
                                    $selected_type_name = $type['name'];
                                    break;
                                }
                            }
                            
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "You selected: {$selected_type_name}\n\nSelect payment period ({$new_page}/{$period_data['total_pages']}):\n\n" . $period_data['menu'] . "Select period:",
                                'Label' => 'Select Period',
                                'ClientState' => "period_{$payment_type_id}_{$context}_page_{$new_page}",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                            break;
                        }
                    }
                    
                    // Handle period selection (1-12 on current page)
                    if ($selection >= 1 && $selection <= 12) {
                        // Parse client state to get page info
                        $current_page = 1;
                        $payment_type_id = '';
                        $context = '';
                        
                        if (preg_match('/^period_(\d+)_(.+)_page_(\d+)$/', $client_state, $matches)) {
                            $payment_type_id = $matches[1];
                            $context = $matches[2];
                            $current_page = intval($matches[3]);
                        } else {
                            // Legacy format without pagination
                            $parts = explode('_', $client_state, 3);
                            $payment_type_id = $parts[1];
                            $context = $parts[2] ?? '';
                        }
                        
                        // Get period data for current page to calculate actual period index
                        $period_data = build_period_menu_page($current_page);
                        $periods = $period_data['periods'];
                        
                        // Calculate actual period index based on page and selection
                        $items_per_page = 4;
                        $start_index = ($current_page - 1) * $items_per_page;
                        $actual_period_index = $selection - 1;
                        
                        // Validate selection is within available periods on current page
                        $min_selection = $start_index + 1;
                        $max_selection = $start_index + min($items_per_page, count($periods) - $start_index);
                        if ($selection < $min_selection || $selection > $max_selection) {
                            // Invalid selection for current page
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => "Invalid selection. Please select from the available options:",
                                'Label' => 'Select Period',
                                'ClientState' => $client_state,
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                            break;
                        }
                        
                        // Get the selected period
                        $selected_period = $periods[$actual_period_index];
                        $period_date = $selected_period['date'];
                        $period_display = $selected_period['display'];
                        
                        // Get payment type name
                        $selected_type_name = '';
                        foreach ($payment_types as $type) {
                            if ($type['id'] == $payment_type_id) {
                                $selected_type_name = $type['name'];
                                break;
                            }
                        }
                        
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'response',
                            'Message' => "Payment: {$selected_type_name}\nPeriod: {$period_display}\n\nEnter amount (GHS):",
                            'Label' => 'Enter Amount',
                            'ClientState' => "amount_{$payment_type_id}_{$period_date}_{$context}",
                            'DataType' => 'input',
                            'FieldType' => 'decimal'
                        ];
                    } else {
                        // Invalid period selection
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'response',
                            'Message' => "Invalid selection. Please select from the available options:",
                            'Label' => 'Select Period',
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
                        // Parse client state: amount_{payment_type_id}_{period_date}_{context}
                        $parts = explode('_', $client_state, 4);
                        $payment_type_id = $parts[1];
                        $period_date = $parts[2] ?? '';
                        $context = $parts[3] ?? '';
                        
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
                                'ClientState' => "confirm_{$payment_type_id}_{$period_date}_{$amount}_self_{$member_id}",
                                'DataType' => 'input',
                                'FieldType' => 'text'
                            ];
                        } else {
                            // Handle different contexts for unregistered users and other member payments
                            $confirmation_message = "Amount: GHS " . number_format($amount, 2) . " for $selected_type_name";
                            
                            // Check if this is an unregistered user paying for a specific member
                            if (str_starts_with($context, 'unregistered_for_')) {
                                $target_member_id = substr($context, 17);
                                // Get member details for confirmation
                                $member_stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) as full_name, crn FROM members WHERE id = ? AND status = 'active'");
                                $member_stmt->bind_param("i", $target_member_id);
                                $member_stmt->execute();
                                $member_result = $member_stmt->get_result();
                                $target_member = $member_result->fetch_assoc();
                                
                                if ($target_member) {
                                    $confirmation_message = "Payment Type: $selected_type_name\nFor: {$target_member['full_name']} ({$target_member['crn']})\nAmount: GHS " . number_format($amount, 2);
                                }
                            } elseif (str_starts_with($context, 'other_')) {
                                // Registered member paying for another member
                                $context_parts = explode('_', $context, 3);
                                $target_member_id = $context_parts[2] ?? null;
                                if ($target_member_id) {
                                    $member_stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) as full_name, crn FROM members WHERE id = ? AND status = 'active'");
                                    $member_stmt->bind_param("i", $target_member_id);
                                    $member_stmt->execute();
                                    $member_result = $member_stmt->get_result();
                                    $target_member = $member_result->fetch_assoc();
                                    
                                    if ($target_member) {
                                        $confirmation_message = "Payment Type: $selected_type_name\nFor: {$target_member['full_name']} ({$target_member['crn']})\nAmount: GHS " . number_format($amount, 2);
                                    }
                                }
                            } elseif ($context === 'unmatched') {
                                // Unregistered user paying for themselves
                                $confirmation_message = "Payment Type: $selected_type_name\nFor: Yourself (unregistered)\nAmount: GHS " . number_format($amount, 2);
                            }
                            
                            $response = [
                                'SessionId' => $session_id,
                                'Type' => 'response',
                                'Message' => $confirmation_message . "\n\nConfirm payment?\n1. Yes, proceed\n2. No, cancel",
                                'Label' => 'Confirm Payment',
                                'ClientState' => "confirm_{$payment_type_id}_{$period_date}_{$amount}_{$context}",
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
                        // Parse client state: confirm_{payment_type_id}_{period_date}_{amount}_{context}
                        $parts = explode('_', $client_state, 5);
                        $payment_type_id = $parts[1];
                        $period_date = $parts[2] ?? '';
                        $amount = floatval($parts[3]);
                        $context = $parts[4] ?? '';
                        log_debug("Confirm parsing - Full state: $client_state, Context: $context");
                        
                        // Get payment type name and period display
                        $selected_type_name = '';
                        foreach ($payment_types as $type) {
                            if ($type['id'] == $payment_type_id) {
                                $selected_type_name = $type['name'];
                                break;
                            }
                        }
                        
                        $period_display = '';
                        if ($period_date) {
                            $period_display = date('F Y', strtotime($period_date));
                        }
                        $payment_description = $selected_type_name;
                        
                        // Determine member info and payment context
                        $member_id = null;
                        $target_member_id = null;
                        $payer_member_id = null;
                        
                        if (str_starts_with($context, 'self_')) {
                            // Self payment by registered member
                            $member_id = substr($context, 5);
                            $target_member_id = $member_id;
                            $payer_member_id = $member_id;
                            $item_description = "$payment_description - Member ID: $member_id, Period: $period_date";
                        } elseif (str_starts_with($context, 'other_')) {
                            // Registered member paying for another member
                            $context_parts = explode('_', $context, 4);
                            $payer_member_id = $context_parts[1] ?? null;
                            $target_member_id = $context_parts[2] ?? null;
                            log_debug("Context parsing - Full context: $context, Payer: $payer_member_id, Target: $target_member_id");
                            // CRITICAL FIX: Use Target ID as the primary member for payment attribution
                            $item_description = "$payment_description - Target ID: $target_member_id, Payer ID: $payer_member_id, Period: $period_date";
                            log_debug("Generated ItemName: $item_description");
                        } elseif (str_starts_with($context, 'unregistered_for_')) {
                            // Unregistered user paying for a member
                            $target_member_id = substr($context, 17);
                            $item_description = "$payment_description - Target ID: $target_member_id, Phone: $phone (unregistered), Period: $period_date";
                        } else {
                            // Unregistered user paying for themselves
                            $item_description = "$payment_description - Phone: $phone (unregistered), Period: $period_date";
                        }
                        
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'AddToCart',
                            'Message' => "Thank you! You will receive a payment prompt for GHS " . number_format($amount, 2) . " for $selected_type_name.",
                            'Item' => [
                                'ItemName' => $item_description,
                                'Qty' => 1,
                                'Price' => $amount
                            ],
                            'DataType' => 'display',
                            'FieldType' => 'text'
                        ];
                    } else {
                        // User cancelled
                        $response = [
                            'SessionId' => $session_id,
                            'Type' => 'release',
                            'Message' => "Payment cancelled. Thank you for using Freeman Methodist Church USSD service.\n\nDial *713*4# to start again.",
                            'Label' => 'Payment Cancelled',
                            'DataType' => 'display',
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
