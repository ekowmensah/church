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
    $date = $p['date'] ?? date('Y-m-d');
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
    $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
    if (!$user_id) {
        $errors[] = 'User session expired or not found.';
        $failed[] = ['type_id'=>$type_id, 'reason'=>'User session expired or not found.'];
        continue;
    }
    if ($member_id) {
        $stmt = $conn->prepare('INSERT INTO payments (member_id, payment_type_id, amount, mode, payment_date, description, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iidsssi', $member_id, $type_id, $amount, $mode, $date, $desc, $user_id);
    } else if ($sundayschool_id) {
        $stmt = $conn->prepare('INSERT INTO payments (sundayschool_id, payment_type_id, amount, mode, payment_date, description, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iidsssi', $sundayschool_id, $type_id, $amount, $mode, $date, $desc, $user_id);
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
    // --- Queue SMS notification asynchronously ---
    $payment_id = $conn->insert_id;
    $sms_sent = false;
    $sms_error = null;
    $sms_debug = [];
    
    // Queue SMS for background processing (non-blocking)
    if ($payment_id && ($member_id || $sundayschool_id)) {
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