<?php
// Accepts JSON POST: { member_id: int, payments: [{type_id, amount, mode, date, desc}] }
//if (session_status() === PHP_SESSION_NONE) session_start();
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

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
    
    // Handle payment period - default to first day of current month if not provided
    $period = $p['period'] ?? date('Y-m-01');
    $period_description = $p['period_text'] ?? '';
    

    $desc = trim($p['desc'] ?? '');
    $cheque_number = trim($p['cheque_number'] ?? '');
    if (!$type_id || !$amount || !$mode || !$date || !$period) {
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
        

        $stmt = $conn->prepare('INSERT INTO payments (member_id, payment_type_id, amount, mode, cheque_number, payment_date, payment_period, payment_period_description, description, recorded_by, church_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iidssssssii', $member_id, $type_id, $amount, $mode, $cheque_number, $date, $period, $period_description, $desc, $user_id, $church_id);
    } else if ($sundayschool_id) {
        // Get church_id from sunday school record
        $church_stmt = $conn->prepare('SELECT church_id FROM sunday_school WHERE id = ?');
        $church_stmt->bind_param('i', $sundayschool_id);
        $church_stmt->execute();
        $church_result = $church_stmt->get_result()->fetch_assoc();
        $church_id = $church_result['church_id'] ?? 1;
        $church_stmt->close();
        

        $stmt = $conn->prepare('INSERT INTO payments (sundayschool_id, payment_type_id, amount, mode, cheque_number, payment_date, payment_period, payment_period_description, description, recorded_by, church_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iidssssssii', $sundayschool_id, $type_id, $amount, $mode, $cheque_number, $date, $period, $period_description, $desc, $user_id, $church_id);
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
    
    // Send SMS immediately for all payment types (harvest and non-harvest)
    require_once __DIR__.'/../includes/payment_sms_template.php';
    require_once __DIR__.'/../includes/sms.php';
    
    // Get member or sunday school details
    if ($member_id) {
        $person_stmt = $conn->prepare('SELECT first_name, last_name, phone FROM members WHERE id = ?');
        $person_stmt->bind_param('i', $member_id);
        $person_stmt->execute();
        $person_data = $person_stmt->get_result()->fetch_assoc();
        $person_stmt->close();
    } else if ($sundayschool_id) {
        $person_stmt = $conn->prepare('SELECT first_name, last_name, contact as phone FROM sunday_school WHERE id = ?');
        $person_stmt->bind_param('i', $sundayschool_id);
        $person_stmt->execute();
        $person_data = $person_stmt->get_result()->fetch_assoc();
        $person_stmt->close();
    } else {
        $person_data = null;
    }
    
    if ($person_data && !empty($person_data['phone'])) {
        // Get church name
        $church_stmt = $conn->prepare('SELECT name FROM churches WHERE id = ?');
        $church_stmt->bind_param('i', $church_id);
        $church_stmt->execute();
        $church_data = $church_stmt->get_result()->fetch_assoc();
        $church_stmt->close();
        
        $person_name = trim($person_data['first_name'] . ' ' . $person_data['last_name']);
        $church_name = $church_data['name'] ?? 'Freeman Methodist Church - KM';
        
        if ($type_id == 4) {
            $yearly_total = get_member_yearly_harvest_total($conn, $member_id);
            $sms_message = get_harvest_payment_sms_message(
                $person_name,
                $amount,
                $church_name,
                $desc,
                $yearly_total
            );
            $sms_type = 'harvest_payment';
        } else {
            // Use period_description if available, else fallback to date
            $period_text = !empty($period_description) ? $period_description : date('F Y', strtotime($date));
            $sms_message = get_payment_sms_message($person_name, $amount, $payment_type_name, $period_text, $desc);
            $sms_type = 'payment';
        }
        // Send SMS
        $sms_result = log_sms($person_data['phone'], $sms_message, $payment_id, $sms_type);
        error_log('Payment SMS sent to ' . $person_data['phone'] . ': ' . json_encode($sms_result));
    }
    // (No queueing, all SMS are sent immediately)

}

// Initialize SMS variables to avoid undefined variable warnings
if (!isset($sms_sent)) $sms_sent = false;
if (!isset($sms_error)) $sms_error = null;
if (!isset($sms_debug)) $sms_debug = null;

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