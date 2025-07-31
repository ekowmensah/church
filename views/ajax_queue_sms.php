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

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

$payment_id = intval($input['payment_id'] ?? 0);
$member_id = intval($input['member_id'] ?? 0);
$sundayschool_id = intval($input['sundayschool_id'] ?? 0);
$amount = floatval($input['amount'] ?? 0);
$payment_type_name = $input['payment_type_name'] ?? '';
$date = $input['date'] ?? '';

if (!$payment_id || (!$member_id && !$sundayschool_id) || !$amount) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
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
                    'description' => $input['description'] ?? '',
                    'date' => $date
                ]
            );
            
            // Check if SMS was sent successfully
            if (isset($sms_result['status']) && $sms_result['status'] === 'success') {
                $sms_sent = true;
            } else {
                $sms_error = $sms_result['error'] ?? $sms_result['message'] ?? 'Unknown SMS error';
                error_log("SMS sending failed: " . $sms_error);
            }
        } catch (Exception $e) {
            $sms_error = $e->getMessage();
            error_log("SMS sending exception: " . $e->getMessage());
        }
    } else {
        $sms_error = 'No phone number available';
    }
} catch (Exception $e) {
    $sms_error = $e->getMessage();
    error_log("Error in SMS queue processing: " . $e->getMessage());
}

echo json_encode([
    'success' => true,
    'sms_sent' => $sms_sent,
    'sms_error' => $sms_error,
    'payment_id' => $payment_id
]);
?>
