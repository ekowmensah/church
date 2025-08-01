<?php
$__start = microtime(true);
// AJAX endpoint for single payment by logged-in member
//if (session_status() === PHP_SESSION_NONE) session_start();
//error_log('SESSION user_id: ' . print_r($_SESSION['user_id'], true));
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
if (!$is_super_admin && !has_permission('access_ajax_single_payment_member')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$member_id = intval($_SESSION['member_id']);
$payment_type_id = intval($_POST['payment_type_id'] ?? 0);
$amount = floatval($_POST['amount'] ?? 0);
// Handle payment date - if only date is provided, append current time
$date = $_POST['payment_date'] ?? date('Y-m-d H:i:s');
if ($date && strlen($date) == 10) { // If date is in Y-m-d format (10 chars), append current time
    $date .= ' ' . date('H:i:s');
}
$description = trim($_POST['description'] ?? '');

// Duplicate check (ignore description for duplicate logic)
$dup_stmt = $conn->prepare('SELECT id FROM payments WHERE member_id=? AND payment_type_id=? AND amount=? AND payment_date=?');
$dup_stmt->bind_param('iids', $member_id, $payment_type_id, $amount, $date);
$dup_stmt->execute();
$dup_stmt->store_result();
if ($dup_stmt->num_rows > 0) {
    echo json_encode(['success' => false, 'error' => 'This payment has already been recorded.']);
    $dup_stmt->close();
    exit;
}
$dup_stmt->close();

if ($payment_type_id && $amount > 0) {
    // For member self-payments, use member_id as recorded_by (or 0 if no user_id available)
    $recorded_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    
    // Get the member's church_id
    $church_stmt = $conn->prepare('SELECT church_id FROM members WHERE id = ?');
    $church_stmt->bind_param('i', $member_id);
    $church_stmt->execute();
    $church_result = $church_stmt->get_result()->fetch_assoc();
    $church_id = $church_result['church_id'] ?? 1; // Default to church_id 1 if not found
    $church_stmt->close();
    
    $stmt = $conn->prepare('INSERT INTO payments (member_id, payment_type_id, amount, payment_date, description, recorded_by, church_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iidssii', $member_id, $payment_type_id, $amount, $date, $description, $recorded_by, $church_id);
    if ($stmt->execute()) {
        $payment_id = $conn->insert_id;
        
        // Check if this is a harvest payment (payment_type_id = 4) and send special SMS
        if ($payment_type_id == 4) {
            require_once __DIR__.'/../includes/payment_sms_template.php';
            require_once __DIR__.'/../includes/sms.php';
            
            // Get member details
            $member_stmt = $conn->prepare('SELECT first_name, last_name, phone, church_id FROM members WHERE id = ?');
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
                    $description,
                    $yearly_total
                );
                
                // Send SMS
                $sms_result = log_sms($member_data['phone'], $sms_message, $payment_id, 'harvest_payment');
                
                // Log SMS attempt
                error_log('Harvest SMS sent to ' . $member_data['phone'] . ': ' . json_encode($sms_result));
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Payment recorded successfully.']);
    } else {
        error_log('SINGLE PAYMENT INSERT ERROR: '. $stmt->error . ' | Values: member_id=' . $member_id . ', payment_type_id=' . $payment_type_id . ', amount=' . $amount . ', date=' . $date . ', desc=' . $description . ', user_id=' . $user_id);
        echo json_encode(['success' => false, 'error' => 'Error: Could not record payment. Please contact admin.']);
    }
    $stmt->close();
error_log('TIMING: Insert: ' . round((microtime(true) - $__after_dup)*1000, 2) . ' ms');
} else {
    echo json_encode(['success' => false, 'error' => 'Please select a payment type and enter a valid amount.']);
}
