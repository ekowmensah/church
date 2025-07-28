<?php
// Accepts JSON POST: { member_id: int, payments: [{type_id, amount, mode, date, desc}] }
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_bulk_payments_single_member')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$member_id = intval($input['member_id'] ?? 0);
$sundayschool_id = intval($input['sundayschool_id'] ?? 0);
$payments = $input['payments'] ?? [];
if ((!$member_id && !$sundayschool_id) || !is_array($payments) || count($payments) === 0) {
    echo json_encode(['success'=>false, 'msg'=>'Invalid data.']);
    exit;
}
$errors = [];
$failed = [];
foreach ($payments as $p) {
    $type_id = intval($p['type_id'] ?? 0);
    $amount = floatval($p['amount'] ?? 0);
    $mode = isset($p['mode']) ? trim($p['mode']) : 'Cash';
// Validate mode against allowed options
$allowed_modes = ['Cash', 'Cheque', 'Transfer', 'POS', 'Online', 'Offline', 'Other'];
if (!in_array($mode, $allowed_modes)) {
    $mode = 'Cash';
}
    // Handle payment date - if only date is provided, append current time
    $date = $p['date'] ?? date('Y-m-d H:i:s');
    if ($date && strlen($date) == 10) { // If date is in Y-m-d format (10 chars), append current time
        $date .= ' ' . date('H:i:s');
    }
    $desc = trim($p['desc'] ?? '');
    if (!$type_id || !$amount || !$mode || !$date) {
        $msg = 'Missing fields for payment type ID '.$type_id;
        $errors[] = $msg;
        $failed[] = ['type_id'=>$type_id, 'reason'=>$msg];
        continue;
    }
    // Validate type_id exists in payment_types and get the type name
    $check = $conn->prepare('SELECT id, name FROM payment_types WHERE id=?');
    $check->bind_param('i', $type_id);
    $check->execute();
    $check_result = $check->get_result();
    $payment_type_data = $check_result->fetch_assoc();
    if (!$payment_type_data) {
        $msg = 'Invalid payment type selected (type ID '.$type_id.')';
        $errors[] = $msg;
        $failed[] = ['type_id'=>$type_id, 'reason'=>$msg];
        $check->close();
        continue;
    }
    $payment_type_name = $payment_type_data['name'];
    $check->close();
    // For member payments, use user_id if available, otherwise use 0
    $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    
    // Get church_id for the payment
    $church_id = 1; // Default fallback
    if ($member_id) {
        // Get church_id from member record
        $church_stmt = $conn->prepare('SELECT church_id FROM members WHERE id = ?');
        $church_stmt->bind_param('i', $member_id);
        $church_stmt->execute();
        $church_result = $church_stmt->get_result()->fetch_assoc();
        $church_id = $church_result['church_id'] ?? 1;
        $church_stmt->close();
        
        $stmt = $conn->prepare('INSERT INTO payments (member_id, payment_type_id, amount, mode, payment_date, description, recorded_by, church_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iidsssii', $member_id, $type_id, $amount, $mode, $date, $desc, $user_id, $church_id);
    } else if ($sundayschool_id) {
        // Get church_id from sunday school record
        $church_stmt = $conn->prepare('SELECT church_id FROM sunday_school WHERE id = ?');
        $church_stmt->bind_param('i', $sundayschool_id);
        $church_stmt->execute();
        $church_result = $church_stmt->get_result()->fetch_assoc();
        $church_id = $church_result['church_id'] ?? 1;
        $church_stmt->close();
        
        $stmt = $conn->prepare('INSERT INTO payments (sundayschool_id, payment_type_id, amount, mode, payment_date, description, recorded_by, church_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iidsssii', $sundayschool_id, $type_id, $amount, $mode, $date, $desc, $user_id, $church_id);
    } else {
        $errors[] = 'No valid member or Sunday School child specified.';
        $failed[] = ['type_id'=>$type_id, 'reason'=>'No valid member or Sunday School child specified.'];
        continue;
    }
    if (!$stmt->execute()) {
        $msg = 'DB error for type '.$type_id;
        if (defined('DEBUG') && DEBUG) {
            $msg .= ': '.$stmt->error;
        }
        $errors[] = $msg;
        $failed[] = ['type_id'=>$type_id, 'reason'=>$msg];
        $stmt->close();
        continue;
    }
    $stmt->close();
    $payment_id = $conn->insert_id;
    
    // Check if this is a harvest payment (payment_type_id = 4) and send special SMS
    if ($type_id == 4 && $member_id) {
        require_once __DIR__.'/../includes/payment_sms_template.php';
        require_once __DIR__.'/../includes/sms.php';
        
        // Get member details
        $member_stmt = $conn->prepare('SELECT first_name, last_name, phone FROM members WHERE id = ?');
        $member_stmt->bind_param('i', $member_id);
        $member_stmt->execute();
        $member_data = $member_stmt->get_result()->fetch_assoc();
        $member_stmt->close();
        
        if ($member_data && !empty($member_data['phone'])) {
            // Get church name
            $church_stmt = $conn->prepare('SELECT name FROM churches WHERE id = ?');
            $church_stmt->bind_param('i', $church_id);
            $church_stmt->execute();
            $church_data = $church_stmt->get_result()->fetch_assoc();
            $church_stmt->close();
            
            $member_name = trim($member_data['first_name'] . ' ' . $member_data['last_name']);
            $church_name = $church_data['name'] ?? 'Freeman Methodist Church - KM';
            $yearly_total = get_member_yearly_harvest_total($conn, $member_id);
            
            // Generate harvest SMS message
            $sms_message = get_harvest_payment_sms_message(
                $member_name,
                $amount,
                $church_name,
                $desc,
                $yearly_total
            );
            
            // Send SMS
            $sms_result = log_sms($member_data['phone'], $sms_message, $payment_id, 'harvest_payment');
            
            // Log SMS attempt
            error_log('Bulk Harvest SMS sent to ' . $member_data['phone'] . ': ' . json_encode($sms_result));
        }
    }
    
    // --- Queue SMS notification asynchronously ---
    $sms_sent = false;
    $sms_error = null;
    $sms_debug = [];
    
    // Queue SMS for background processing (non-blocking) - Skip for harvest payments as they have custom SMS
    if ($payment_id && ($member_id || $sundayschool_id) && $type_id != 4) {
        $sms_queue_data = [
            'payment_id' => $payment_id,
            'member_id' => $member_id,
            'sundayschool_id' => $sundayschool_id,
            'amount' => $amount,
            'payment_type_name' => $payment_type_name,
            'date' => $date,
            'description' => $desc
        ];
        
        // Use cURL to make non-blocking request to SMS queue
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://localhost' . dirname($_SERVER['REQUEST_URI']) . '/ajax_queue_sms.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sms_queue_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Cookie: ' . $_SERVER['HTTP_COOKIE'] ?? ''
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Very short timeout for non-blocking
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        
        // Execute in background (ignore response)
        curl_exec($ch);
        curl_close($ch);
        
        $sms_debug = ['queued' => true, 'payment_id' => $payment_id];
    }
}

$response = [
    'success' => count($errors)===0,
    'msg' => count($errors) ? implode('; ',$errors) : 'Payments recorded.',
    'sms_sent' => $sms_sent,
    'sms_error' => $sms_error,
    'debug' => $sms_debug
];
if (!empty($failed)) {
    $response['failed'] = $failed;
}
echo json_encode($response);