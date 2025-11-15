<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../includes/payment_sms_template.php';
require_once __DIR__.'/../includes/sms.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}
// Permission check
if (!has_permission('view_dashboard')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}


// Get POST data - expect array of SMS requests
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['sms_requests']) || !is_array($input['sms_requests'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid input - expected sms_requests array']);
    exit;
}

$sms_requests = $input['sms_requests'];
$results = [];
$success_count = 0;
$error_count = 0;

foreach ($sms_requests as $request) {
    $payment_id = intval($request['payment_id'] ?? 0);
    $member_id = intval($request['member_id'] ?? 0);
    $sundayschool_id = intval($request['sundayschool_id'] ?? 0);
    $amount = floatval($request['amount'] ?? 0);
    $payment_type_name = $request['payment_type_name'] ?? '';
    $date = $request['date'] ?? '';
    $description = $request['description'] ?? '';

    if (!$payment_id || (!$member_id && !$sundayschool_id) || !$amount) {
        $results[] = [
            'payment_id' => $payment_id,
            'success' => false,
            'error' => 'Missing required parameters'
        ];
        $error_count++;
        continue;
    }

    $sms_sent = false;
    $sms_error = null;

    try {
        // Fetch member or child info for SMS
        if ($member_id) {
            $stmt = $conn->prepare('SELECT first_name, middle_name, last_name, phone FROM members WHERE id=?');
            $stmt->bind_param('i', $member_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $person = $result->fetch_assoc();
            $stmt->close();
        } else if ($sundayschool_id) {
            $stmt = $conn->prepare('SELECT first_name, middle_name, last_name, contact as phone FROM sunday_school WHERE id=?');
            $stmt->bind_param('i', $sundayschool_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $person = $result->fetch_assoc();
            $stmt->close();
        } else {
            $person = null;
        }
        
        if ($person && !empty($person['phone'])) {
            $full_name = trim(($person['first_name'] ?? '').' '.($person['middle_name'] ?? '').' '.($person['last_name'] ?? ''));
            $sms_message = get_payment_sms_message($full_name, $amount, $payment_type_name, $date);
            
            // Send SMS
            $sms_result = send_sms($person['phone'], $sms_message);
            
            // Log the SMS attempt
            try {
                log_sms(
                    $person['phone'], 
                    $sms_message,
                    $payment_id,
                    'payment',
                    null, // Use default sender
                    [
                        'member_name' => $full_name,
                        'amount' => $amount,
                        'description' => $description,
                        'date' => $date
                    ]
                );
                
                // Check if SMS was sent successfully
                if (isset($sms_result['status']) && $sms_result['status'] === 'success') {
                    $sms_sent = true;
                } else {
                    $sms_error = $sms_result['error'] ?? $sms_result['message'] ?? 'Unknown SMS error';
                    error_log("Bulk SMS sending failed for payment_id $payment_id: " . $sms_error);
                }
            } catch (Exception $e) {
                $sms_error = $e->getMessage();
                error_log("Bulk SMS sending exception for payment_id $payment_id: " . $e->getMessage());
            }
        } else {
            $sms_error = 'No phone number available';
        }
    } catch (Exception $e) {
        $sms_error = $e->getMessage();
        error_log("Error in bulk SMS queue processing for payment_id $payment_id: " . $e->getMessage());
    }

    $results[] = [
        'payment_id' => $payment_id,
        'member_id' => $member_id,
        'sundayschool_id' => $sundayschool_id,
        'success' => $sms_sent,
        'error' => $sms_error
    ];

    if ($sms_sent) {
        $success_count++;
    } else {
        $error_count++;
    }

    // Small delay between SMS sends to avoid overwhelming the SMS service
    usleep(200000); // 200ms delay
}

echo json_encode([
    'success' => $error_count === 0,
    'total_processed' => count($sms_requests),
    'success_count' => $success_count,
    'error_count' => $error_count,
    'results' => $results
]);
?>
