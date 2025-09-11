<?php
// Minimal fallback logger for debugging
function _test_log($msg) {
    file_put_contents(__DIR__ . '/../logs/hubtel_callback_test.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}
_test_log('hubtel_callback.php called');
// Hubtel callback handler: receives payment status update from Hubtel
// This is the endpoint you configure in Hubtel dashboard as the callbackUrl
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../models/PaymentIntent.php';
require_once __DIR__.'/../models/Payment.php';

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
$hubtelTransactionId = $data['Data']['TransactionId'] ?? $data['Data']['transactionId'] ?? null;

$intentModel = new PaymentIntent();
$paymentModel = new Payment();

if ($clientReference) {
    log_debug("Fetched clientReference: $clientReference");
    $intent = $intentModel->getByReference($conn, $clientReference);
    if ($intent) {
        log_debug('Found PaymentIntent: '.json_encode($intent));
        $intentModel->updateStatus($conn, $clientReference, $status);
        log_debug("Updated PaymentIntent status to $status");
        
        // Update hubtel_transaction_id if we received one and don't have it stored
        if ($hubtelTransactionId && (!isset($intent['hubtel_transaction_id']) || empty($intent['hubtel_transaction_id']) || $intent['hubtel_transaction_id'] === $clientReference)) {
            $update_txn_stmt = $conn->prepare("UPDATE payment_intents SET hubtel_transaction_id = ? WHERE client_reference = ?");
            $update_txn_stmt->bind_param('ss', $hubtelTransactionId, $clientReference);
            $update_txn_stmt->execute();
            log_debug("Updated hubtel_transaction_id to: $hubtelTransactionId");
        }
        if ($status === 'Completed') {
            require_once __DIR__.'/../includes/sms.php';
            
            $paymentsToInsert = [];
            if (!empty($intent['bulk_breakdown'])) {
                $bulk_items = json_decode($intent['bulk_breakdown'], true);
                if (is_array($bulk_items)) {
                    foreach ($bulk_items as $item) {
                        $paymentsToInsert[] = [
                            'member_id' => $item['member_id'] ?? $intent['member_id'],
                            'amount' => $item['amount'],
                            'description' => $item['desc'] ?? $item['typeName'],
                            'payment_date' => date('Y-m-d H:i:s'),
                            'client_reference' => $clientReference,
                            'status' => $status,
                            'church_id' => $item['church_id'] ?? $intent['church_id'],
                            'payment_type_id' => $item['payment_type_id'] ?? null,
                            'payment_period' => $item['payment_period'] ?? null,
                            'payment_period_description' => $item['payment_period_description'] ?? null,
                            'recorded_by' => 'Online Payment',
                            'mode' => 'Online'
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
                    'recorded_by' => 'Online Payment',
                    'mode' => 'Online'
                ];
            }
            
            foreach ($paymentsToInsert as $paymentRow) {
                log_debug('About to insert payment: '.json_encode($paymentRow));
                $result = $paymentModel->add($conn, $paymentRow);
                log_debug('Payment add result: '.var_export($result, true));
                
                // Send SMS notification for each payment
                log_debug('Checking SMS trigger conditions - result: '.json_encode($result));
                if ($result && isset($result['id'])) {
                    log_debug('SMS trigger condition met, payment ID: '.$result['id']);
                    try {
                        // Get member details
                        $member_stmt = $conn->prepare('SELECT CONCAT(first_name, " ", last_name) as full_name, phone FROM members WHERE id = ?');
                        $member_stmt->bind_param('i', $paymentRow['member_id']);
                        $member_stmt->execute();
                        $member = $member_stmt->get_result()->fetch_assoc();
                        
                        log_debug('Member query result: '.json_encode($member));
                        if ($member && !empty($member['phone'])) {
                            log_debug('Member has phone, proceeding with SMS');
                            // Get church name
                            $church_stmt = $conn->prepare('SELECT name FROM churches WHERE id = ?');
                            $church_stmt->bind_param('i', $paymentRow['church_id']);
                            $church_stmt->execute();
                            $church = $church_stmt->get_result()->fetch_assoc();
                            $church_name = $church['name'] ?? 'Church';
                            log_debug('Church name: '.$church_name);
                            
                            $amount = number_format($paymentRow['amount'], 2);
                            $full_name = $member['full_name'];
                            $description = $paymentRow['description'];
                            
                            // Check if this is a harvest payment (payment_type_id = 4)
                            if ($paymentRow['payment_type_id'] == 4) {
                                log_debug('Processing harvest payment SMS');
                                // Calculate yearly harvest total
                                $year = date('Y');
                                $yearly_stmt = $conn->prepare('SELECT SUM(amount) as yearly_total FROM payments WHERE member_id = ? AND payment_type_id = 4 AND YEAR(payment_date) = ? AND status = "Completed"');
                                $yearly_stmt->bind_param('ii', $paymentRow['member_id'], $year);
                                $yearly_stmt->execute();
                                $yearly_result = $yearly_stmt->get_result()->fetch_assoc();
                                $yearly_total = number_format($yearly_result['yearly_total'] ?? 0, 2);
                                
                                $sms_message = "Hi $full_name, your payment of ₵$amount has been paid to $church_name as $description. Your Total Harvest amount for the year $year is ₵$yearly_total";
                            } else {
                                log_debug('Processing regular payment SMS');
                                $sms_message = "Hi $full_name, your payment of ₵$amount has been successfully processed for $church_name. Description: $description. Thank you!";
                            }
                            
                            log_debug('About to send SMS: '.$sms_message);
                            $sms_result = send_sms($member['phone'], $sms_message);
                            log_debug('SMS sent for payment ID '.$result['id'].': '.json_encode($sms_result));
                            
                            // Log SMS to database
                            $sms_status = (isset($sms_result['status']) && $sms_result['status'] === 'success') ? 'success' : 'fail';
                            $sms_response = json_encode($sms_result);
                            $sms_log_stmt = $conn->prepare('INSERT INTO sms_logs (member_id, phone, message, type, status, provider, sent_at, response) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)');
                            $sms_type = 'payment_notification';
                            $provider = 'arkesel';
                            $sms_log_stmt->bind_param('issssss', $paymentRow['member_id'], $member['phone'], $sms_message, $sms_type, $sms_status, $provider, $sms_response);
                            $sms_log_stmt->execute();
                            log_debug('SMS logged to database');
                        } else {
                            log_debug('Member has no phone or member not found');
                        }
                    } catch (Exception $e) {
                        log_debug('SMS error for payment: '.$e->getMessage());
                    }
                } else {
                    log_debug('SMS trigger condition NOT met - no payment ID returned or result is false');
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
