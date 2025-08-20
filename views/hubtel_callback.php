<?php
// Hubtel callback handler: receives payment status update from Hubtel
// This is the endpoint you configure in Hubtel dashboard as the callbackUrl
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../models/PaymentIntent.php';
require_once __DIR__.'/../models/Payment.php';
require_once __DIR__.'/../includes/sms.php';
require_once __DIR__.'/../includes/payment_sms_template.php';

$debug_log = __DIR__.'/../logs/hubtel_callback_debug.log';

function log_debug($msg) {
    global $debug_log;
    file_put_contents($debug_log, date('c')." $msg\n", FILE_APPEND);
}

// Log raw input for debugging
$raw_input = file_get_contents('php://input');
file_put_contents(__DIR__.'/../logs/hubtel_callback.log', date('c')."\n".$raw_input."\n", FILE_APPEND);
log_debug('Callback entered');

// Parse and validate input
$data = json_decode($raw_input, true);
log_debug('Callback raw data: '.json_encode($data));
if (!$data || !isset($data['Data']['Status'])) {
    log_debug('Invalid callback data');
    http_response_code(400);
    echo 'Invalid callback';
    exit;
}

$hubtelStatus = $data['Data']['Status'];
$status = match (strtolower($hubtelStatus)) {
    'success' => 'Completed',
    'failed', 'cancelled' => 'Failed',
    default => 'Pending',
};
$clientReference = $data['Data']['ClientReference'] ?? '';

$intentModel = new PaymentIntent();
$paymentModel = new Payment();

if ($clientReference) {
    log_debug("Fetched clientReference: $clientReference");
    $intent = $intentModel->getByReference($conn, $clientReference);
    if ($intent) {
        log_debug('Found PaymentIntent: '.json_encode($intent));
        $intentModel->updateStatus($conn, $clientReference, $status);
        log_debug("Updated PaymentIntent status to $status");
        if ($status === 'Completed') {
            $paymentsToInsert = [];
            if (!empty($intent['bulk_breakdown'])) {
                $bulk_items = json_decode($intent['bulk_breakdown'], true);
                if (is_array($bulk_items)) {
                    foreach ($bulk_items as $item) {
                        $paymentsToInsert[] = [
                            'member_id' => $item['member_id'] ?? $intent['member_id'],
                            'amount' => $item['amount'],
                            'description' => $item['typeName'] . ($item['desc'] ? (': ' . $item['desc']) : ''),
                            'payment_date' => date('Y-m-d H:i:s'),
                            'client_reference' => $clientReference,
                            'status' => $status,
                            'church_id' => $item['church_id'] ?? $intent['church_id'],
                            'payment_type_id' => $item['payment_type_id'] ?? null,
                            'payment_period' => $item['payment_period'] ?? null,
                            'payment_period_description' => $item['payment_period_description'] ?? null,
                            'recorded_by' => 'Self',
                            'mode' => 'Hubtel'
                        ];
                    }
                }
            } else {
                $paymentsToInsert[] = [
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
                ];
            }
            foreach ($paymentsToInsert as $paymentRow) {
    $result = $paymentModel->add($conn, $paymentRow);
    log_debug('Payment add result: '.var_export($result, true).'; Data: '.json_encode($paymentRow));
    // SMS notification
    $phone = $intent['customer_phone'] ?? null;
    $member_name = $intent['customer_name'] ?? '';
    $church_name = 'Freeman Methodist Church - KM';
    $description = $paymentRow['description'] ?? '';
    $amount = $paymentRow['amount'] ?? '';
    $payment_type_id = $paymentRow['payment_type_id'] ?? null;
    $yearly_total = null;
    if ($payment_type_id == 4 && $intent['member_id']) {
        $yearly_total = get_member_yearly_harvest_total($conn, $intent['member_id']);
    }
    if ($phone) {
        if ($payment_type_id == 4) {
            $sms_message = get_harvest_payment_sms_message($member_name, $amount, $church_name, $description, $yearly_total);
        } else {
            $sms_message = get_payment_sms_message($member_name, $amount, $description);
        }
        $sms_result = send_sms($phone, $sms_message);
        log_debug('SMS sent to ' . $phone . ': ' . json_encode($sms_result));
    } else {
        log_debug('No phone number found in intent for SMS notification.');
    }
}
        }
    } else {
        log_debug('No PaymentIntent found for clientReference');
    }
} else {
    log_debug('No clientReference in callback data');
}

// Respond OK to Hubtel
http_response_code(200);
echo 'OK';
