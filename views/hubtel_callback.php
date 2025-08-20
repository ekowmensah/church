<?php
// Hubtel callback handler: receives payment status update from Hubtel
// This is the endpoint you configure in Hubtel dashboard as the callbackUrl
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../models/Payment.php';

// Log raw input for debugging
file_put_contents(__DIR__.'/../logs/hubtel_callback.log', date('c')."\n".file_get_contents('php://input')."\n", FILE_APPEND);

$debug_log = __DIR__.'/../logs/hubtel_callback_debug.log';
if (!file_exists($debug_log)) {
    @touch($debug_log);
    if (!file_exists($debug_log)) {
        error_log('Failed to create debug log file: ' . $debug_log);
    }
}
file_put_contents($debug_log, date('c')." Callback entered\n", FILE_APPEND);

$data = json_decode(file_get_contents('php://input'), true);
file_put_contents($debug_log, date('c')." Callback raw data: ".json_encode($data)."\n", FILE_APPEND);
if (!$data || !isset($data['Data']['Status'])) {
    file_put_contents($debug_log, date('c')." Invalid callback data\n", FILE_APPEND);
    http_response_code(400);
    echo 'Invalid callback';
    exit;
}

$hubtelStatus = $data['Data']['Status']; // e.g. 'Success', 'Failed', 'Cancelled', etc.
// Map Hubtel status to our system status
switch (strtolower($hubtelStatus)) {
    case 'success':
        $status = 'Completed';
        break;
    case 'failed':
    case 'cancelled':
        $status = 'Failed';
        break;
    default:
        $status = 'Pending';
        break;
}
$invoiceToken = $data['Data']['InvoiceToken'] ?? '';
$amount = $data['Data']['Amount'] ?? 0;
$clientReference = $data['Data']['ClientReference'] ?? '';

// Use PaymentIntent model to update status and, if success, create payment
require_once __DIR__.'/../models/PaymentIntent.php';
require_once __DIR__.'/../models/Payment.php';
$intentModel = new PaymentIntent();
$paymentModel = new Payment();
if ($clientReference) {
    file_put_contents($debug_log, date('c')." Fetched clientReference: $clientReference\n", FILE_APPEND);
    $intent = $intentModel->getByReference($conn, $clientReference);
    if ($intent) {
        file_put_contents($debug_log, date('c')." Found PaymentIntent: ".json_encode($intent)."\n", FILE_APPEND);
        file_put_contents($debug_log, date('c')." About to update status. Current status: $status\n", FILE_APPEND);
        $intentModel->updateStatus($conn, $clientReference, $status);
        file_put_contents($debug_log, date('c')." Updated PaymentIntent status to $status\n", FILE_APPEND);
        if ($status === 'Completed') {
            if (!empty($intent['bulk_breakdown'])) {
                $bulk_items = json_decode($intent['bulk_breakdown'], true);
                if (is_array($bulk_items)) {
                    foreach ($bulk_items as $item) {
                        $result = $paymentModel->add($conn, [
                            'member_id' => $item['member_id'] ?? $intent['member_id'],
                            'amount' => $item['amount'],
                            'description' => $item['typeName'] . ($item['desc'] ? (': ' . $item['desc']) : ''),
                            'payment_date' => date('Y-m-d H:i:s'),
                            'client_reference' => $clientReference,
                            'status' => $status,
                            'church_id' => $item['church_id'] ?? $intent['church_id'],
                            'payment_type_id' => $item['payment_type_id'] ?? ($item['typeId'] ?? null),
                            'payment_period' => $item['payment_period'] ?? ($item['period'] ?? null),
                            'payment_period_description' => $item['payment_period_description'] ?? ($item['periodText'] ?? null),
                            'recorded_by' => 'Self',
                            'mode' => 'Hubtel'
                        ]);
                        file_put_contents($debug_log, date('c')." Bulk payment add result: ".var_export($result, true)."\n", FILE_APPEND);
                        // --- SMS Notification ---
                        require_once __DIR__.'/../includes/payment_sms_template.php';
                        require_once __DIR__.'/../models/Member.php';
                        $memberModel = new Member();
                        $member = $memberModel->findById($conn, $item['member_id'] ?? $intent['member_id']);
                        if ($member && !empty($member['phone']) && $member['sms_notifications_enabled']) {
                            $sms_message = '';
                            if (($item['typeId'] ?? null) == 4) { // Harvest
                                $yearly_total = get_member_yearly_harvest_total($conn, $member['id']);
                                $sms_message = get_harvest_payment_sms_message($member['name'], $item['amount'], 'Freeman Methodist Church - KM', $item['desc'] ?? '', $yearly_total);
                            } else {
                                $sms_message = get_payment_sms_message($member['name'], $item['amount'], $item['typeName'] ?? '', $item['date'] ?? null);
                            }
                            require_once __DIR__.'/../includes/sms.php';
                            log_sms($member['phone'], $sms_message, $result, 'payment');
                        }
                    }
                }
            } else {
                $result = $paymentModel->add($conn, [
                    'member_id' => $intent['member_id'],
                    'amount' => $intent['amount'],
                    'description' => $intent['description'],
                    'payment_date' => date('Y-m-d H:i:s'),
                    'client_reference' => $clientReference,
                    'status' => $status,
                    'church_id' => $intent['church_id'],
                    'payment_type_id' => $intent['payment_type_id'],
                    'payment_period' => $intent['payment_period'],
                    'payment_period_description' => $intent['payment_period_description'],
                    'recorded_by' => 'Self',
                    'mode' => 'Hubtel'
                ]);
                file_put_contents($debug_log, date('c')." Single payment add result: ".var_export($result, true)."\n", FILE_APPEND);
                // --- SMS Notification ---
                require_once __DIR__.'/../includes/payment_sms_template.php';
                require_once __DIR__.'/../models/Member.php';
                $memberModel = new Member();
                $member = $memberModel->findById($conn, $intent['member_id']);
                if ($member && !empty($member['phone']) && $member['sms_notifications_enabled']) {
                    $sms_message = '';
                    if (($intent['payment_type_id'] ?? null) == 4) { // Harvest
                        $yearly_total = get_member_yearly_harvest_total($conn, $member['id']);
                        $sms_message = get_harvest_payment_sms_message($member['name'], $intent['amount'], 'Freeman Methodist Church - KM', $intent['description'] ?? '', $yearly_total);
                    } else {
                        $sms_message = get_payment_sms_message($member['name'], $intent['amount'], $intent['description'] ?? '', $intent['payment_date'] ?? null);
                    }
                    require_once __DIR__.'/../includes/sms.php';
                    log_sms($member['phone'], $sms_message, $result, 'payment');
                }
            
        }
    }
}

// Respond OK to Hubtel
http_response_code(200);
echo 'OK';
