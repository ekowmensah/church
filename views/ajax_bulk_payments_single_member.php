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
    $mode = 'Cash'; // Force all bulk payments to Cash
    $date = $p['date'] ?? date('Y-m-d');
    $desc = trim($p['desc'] ?? '');
    if (!$type_id || !$amount || !$mode || !$date) {
        $msg = 'Missing fields for payment type ID '.$type_id;
        $errors[] = $msg;
        $failed[] = ['type_id'=>$type_id, 'reason'=>$msg];
        continue;
    }
    // Validate type_id exists in payment_types
    $check = $conn->prepare('SELECT id FROM payment_types WHERE id=?');
    $check->bind_param('i', $type_id);
    $check->execute();
    $check->store_result();
    if ($check->num_rows === 0) {
        $msg = 'Invalid payment type selected (type ID '.$type_id.')';
        $errors[] = $msg;
        $failed[] = ['type_id'=>$type_id, 'reason'=>$msg];
        $check->close();
        continue;
    }
    $check->close();
    if ($member_id) {
        $stmt = $conn->prepare('INSERT INTO payments (member_id, payment_type_id, amount, mode, payment_date, description) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iidsss', $member_id, $type_id, $amount, $mode, $date, $desc);
    } else if ($sundayschool_id) {
        $stmt = $conn->prepare('INSERT INTO payments (sundayschool_id, payment_type_id, amount, mode, payment_date, description) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iidsss', $sundayschool_id, $type_id, $amount, $mode, $date, $desc);
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
    // --- Send SMS notification ---
    require_once __DIR__.'/../includes/payment_sms_template.php';
    require_once __DIR__.'/../includes/sms.php';
    
    // Track if SMS was sent successfully
    $sms_sent = false;
    $sms_error = null;
    $sms_debug = [];
    
    // Fetch member or child info for SMS
    if ($member_id) {
        $mstmt = $conn->prepare('SELECT first_name, middle_name, last_name, phone FROM members WHERE id=?');
        $mstmt->bind_param('i', $member_id);
        $mstmt->execute();
        $mresult = $mstmt->get_result();
        $person = $mresult->fetch_assoc();
    } else if ($sundayschool_id) {
        $mstmt = $conn->prepare('SELECT first_name, middle_name, last_name, contact as phone FROM sunday_school WHERE id=?');
        $mstmt->bind_param('i', $sundayschool_id);
        $mstmt->execute();
        $mresult = $mstmt->get_result();
        $person = $mresult->fetch_assoc();
    } else {
        $person = null;
    }
    
    if ($person && !empty($person['phone'])) {
        try {
            $full_name = trim(($person['first_name'] ?? '').' '.($person['middle_name'] ?? '').' '.($person['last_name'] ?? ''));
            $sms_message = get_payment_sms_message($full_name, $amount, $desc, $date);
            $sms_result = send_sms($person['phone'], $sms_message);

            // Log the SMS attempt
            $sms_debug = [
                'member_phone' => $person['phone'],
                'sms_message' => $sms_message,
                'payment_id' => $conn->insert_id,
                'template_data' => [
                    'member_name' => $full_name,
                    'amount' => $amount,
                    'description' => $desc,
                    'date' => $date
                ]
            ];

            try {
                // Log the SMS attempt
                log_sms(
                    $person['phone'], 
                    $sms_message,
                    $conn->insert_id, // Payment ID
                    'payment',
                    null, // Use default sender
                    [
                        'member_name' => $full_name,
                        'amount' => $amount,
                        'description' => $desc,
                        'date' => $date
                    ]
                );

                // Check if SMS was sent successfully
                if (isset($sms_result['status']) && $sms_result['status'] === 'success') {
                    $sms_sent = true;
                } elseif (isset($sms_result['error'])) {
                    $sms_error = $sms_result['error'];
                    $sms_debug['error'] = $sms_result;
                    error_log("SMS sending failed: " . $sms_error);
                } else {
                    $sms_error = $sms_result['message'] ?? 'Unknown error';
                    $sms_debug['error'] = $sms_result;
                }
            } catch (Exception $e) {
                $sms_error = $e->getMessage();
                $sms_debug['exception'] = $e->getMessage();
                error_log("SMS sending exception: " . $e->getMessage());
            }
        } catch (Exception $e) {
            error_log("Error sending SMS: " . $e->getMessage());
            $sms_error = $e->getMessage();
        }
    }
    $mstmt->close();
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
