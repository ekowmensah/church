<?php
// Paystack callback handler (simplified, production should verify signature)
require_once __DIR__.'/../config/config.php';

// Get transaction reference from query string
$ref = $_GET['reference'] ?? '';
if (!$ref) {
    die('No reference supplied.');
}

// Verify transaction with Paystack
$paystack_secret_key = defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : 'sk_test_5db9d47a6fa119ea1ebdbf34965c2452c03f2c9f';
$ch = curl_init('https://api.paystack.co/transaction/verify/' . urlencode($ref));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $paystack_secret_key,
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    die('Curl error: '.$err);
}

$data = json_decode($response, true);
if (!$data || !$data['status']) {
    die('Paystack error: '.($data['message'] ?? 'Unknown error'));
}

$trx = $data['data'] ?? [];
if ($trx['status'] === 'success') {
    $mode = 'paystack';
    $paid_at = $trx['paid_at'] ?? date('Y-m-d H:i:s');
    $recorded_by = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    $success_count = 0;
    $fail_count = 0;
    $sms_fail_count = 0;
    require_once __DIR__.'/../includes/sms.php';
    // Bulk payment support
    // DEBUG: Show callback metadata for bulk payments
        echo '<pre style="background:#f9f9a9; color:#333; padding:10px;">Bulk Callback Metadata:\n'.htmlspecialchars(print_r($trx['metadata'], true)).'</pre>';
        if (!empty($trx['metadata']['bulk_items'])) {
        $bulk_errors = [];
        foreach ($trx['metadata']['bulk_items'] as $item) {
            $member_id = $item['member_id'] ?? ($trx['metadata']['member_id'] ?? null);
            $church_id = $item['church_id'] ?? ($trx['metadata']['church_id'] ?? null);
            $payment_type_id = $item['typeId'] ?? ($trx['metadata']['payment_type_id'] ?? null);
            $amount = isset($item['amount']) ? floatval($item['amount']) : 0;
            $description = $item['desc'] ?? ($item['description'] ?? 'Bulk Paystack Payment');
            // Validate all foreign keys
            $valid = true;
            $reason = '';
            if (!$member_id || !$conn->query("SELECT 1 FROM members WHERE id=".(int)$member_id)->fetch_row()) {
                $valid = false;
                $reason = 'Invalid member';
            } elseif (!$church_id || !$conn->query("SELECT 1 FROM churches WHERE id=".(int)$church_id)->fetch_row()) {
                $valid = false;
                $reason = 'Invalid church';
            } elseif (!$payment_type_id || !$conn->query("SELECT 1 FROM payment_types WHERE id=".(int)$payment_type_id." AND active=1")->fetch_row()) {
                $valid = false;
                $reason = 'Invalid payment type';
            }
            if (!$valid) {
                $fail_count++;
                $bulk_errors[] = [
                    'member_id'=>$member_id,
                    'church_id'=>$church_id,
                    'payment_type_id'=>$payment_type_id,
                    'reason'=>$reason
                ];
                continue;
            }
            try {
                $stmt = $conn->prepare('INSERT INTO payments (member_id, church_id, payment_type_id, amount, payment_date, recorded_by, mode, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iiidssss', $member_id, $church_id, $payment_type_id, $amount, $paid_at, $recorded_by, $mode, $description);
                $ok = $stmt->execute();
                if ($ok) {
                    $success_count++;
                    // Send SMS notification for each payment
                    $sms_result = send_payment_sms($member_id, $amount, $description, $paid_at, $mode);
                    if (!$sms_result) $sms_fail_count++;
                } else {
                    $fail_count++;
                    $bulk_errors[] = [
                        'member_id'=>$member_id,
                        'church_id'=>$church_id,
                        'payment_type_id'=>$payment_type_id,
                        'reason'=>'DB insert failed'
                    ];
                }
            } catch (Exception $e) {
                error_log('Paystack bulk callback DB error: '.$e->getMessage());
                $fail_count++;
                $bulk_errors[] = [
                    'member_id'=>$member_id,
                    'church_id'=>$church_id,
                    'payment_type_id'=>$payment_type_id,
                    'reason'=>'Exception: '.$e->getMessage()
                ];
            }
        }

        echo '<h2>Bulk Payment Result</h2>';
        echo '<p>'.$success_count.' payment(s) recorded.';
        if ($fail_count) echo ' <span class="text-danger">'.$fail_count.' failed to record.</span>';
        if ($sms_fail_count) echo ' <span class="text-warning">'.$sms_fail_count.' SMS failed.</span>';
        echo '</p>';
        if (!empty($bulk_errors)) {
            echo '<div class="alert alert-danger"><strong>Failed Payments:</strong><ul>';
            foreach ($bulk_errors as $err) {
                $typeId = htmlspecialchars($err['payment_type_id']);
                $reason = htmlspecialchars($err['reason']);
                echo '<li>Member ID: '.htmlspecialchars($err['member_id']).', Type ID: '.$typeId.' - '.$reason.'</li>';
            }
            echo '</ul></div>';
        }
    } else {
        // Single payment
        $amount = isset($trx['amount']) ? ($trx['amount']/100) : 0;
        $description = $trx['metadata']['description'] ?? '';
        $reference = $trx['reference'] ?? '';
        $member_id = $trx['metadata']['member_id'] ?? null;
        $church_id = $trx['metadata']['church_id'] ?? null;
        $payment_type_id = $trx['metadata']['payment_type_id'] ?? null;
        // DEBUG: Show callback metadata for single payments
        echo '<pre style="background:#a9eaf9; color:#333; padding:10px;">Single Callback Metadata:\n'.htmlspecialchars(print_r($trx['metadata'], true)).'\nResolved payment_type_id: '.htmlspecialchars($payment_type_id).'</pre>';
        $inserted = false;
        try {
            // Validate member_id
            if (!$member_id || !$conn->query("SELECT 1 FROM members WHERE id=".(int)$member_id)->fetch_row()) {
                error_log("Invalid or missing member_id: " . print_r($member_id, true));
                echo '<div class="alert alert-danger">Invalid member selected for payment. Please contact admin.</div>';
                return;
            }
            // Validate church_id
            if (!$church_id || !$conn->query("SELECT 1 FROM churches WHERE id=".(int)$church_id)->fetch_row()) {
                error_log("Invalid or missing church_id: " . print_r($church_id, true));
                echo '<div class="alert alert-danger">Invalid church selected for payment. Please contact admin.</div>';
                return;
            }
            // Validate payment_type_id (must exist and be active)
            if (!$payment_type_id || !$conn->query("SELECT 1 FROM payment_types WHERE id=".(int)$payment_type_id." AND active=1")->fetch_row()) {
                error_log("Invalid, missing, or inactive payment_type_id: " . print_r($payment_type_id, true));
                echo '<div class="alert alert-danger">Invalid or inactive payment type selected. Please contact admin.</div>';
                return;
            }
            $stmt = $conn->prepare('INSERT INTO payments (member_id, church_id, payment_type_id, amount, payment_date, recorded_by, mode, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('iiidssss', $member_id, $church_id, $payment_type_id, $amount, $paid_at, $recorded_by, $mode, $description);
            $inserted = $stmt->execute();
        } catch (Exception $e) {
            error_log('Paystack callback DB error: '.$e->getMessage());
        }
        echo '<h2>Payment Successful!</h2><p>Your payment has been received.</p>';
        if (!$inserted) {
            error_log('Paystack payment insert failed. Params: member_id=' . print_r($member_id, true) . ', church_id=' . print_r($church_id, true) . ', payment_type_id=' . print_r($payment_type_id, true) . ', amount=' . print_r($amount, true) . ', paid_at=' . print_r($paid_at, true) . ', recorded_by=' . print_r($recorded_by, true) . ', mode=' . print_r($mode, true) . ', description=' . print_r($description, true));
            error_log('MySQL error: ' . $stmt->error);
            echo '<div class="alert alert-warning">Payment was successful, but could not be recorded in the system. Please contact admin.</div>';
        } else {
            // Send SMS notification for single payment
            $sms_result = send_payment_sms($member_id, $amount, $description, $paid_at, $mode);
            if (!$sms_result) {
                echo '<div class="alert alert-warning">Payment SMS could not be sent.</div>';
            }
        }
    }
} else {
    echo '<h2>Payment Failed</h2><p>Status: '.htmlspecialchars($trx['status']).'</p>';
}
