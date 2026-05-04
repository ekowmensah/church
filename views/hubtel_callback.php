<?php
// Minimal fallback logger for debugging
function _test_log($msg) {
    file_put_contents(__DIR__ . '/../logs/hubtel_callback_test.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

_test_log('hubtel_callback.php called');

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../models/PaymentIntent.php';
require_once __DIR__.'/../models/Payment.php';

$debug_log = __DIR__.'/../logs/hubtel_callback_debug.log';

function log_debug($msg) {
    global $debug_log;
    file_put_contents($debug_log, date('c') . " $msg\n", FILE_APPEND);
}

function normalize_callback_phone($phone) {
    $phone = preg_replace('/^\+233/', '0', (string) $phone);
    $phone = preg_replace('/^233/', '0', $phone);
    if (strlen($phone) === 9 && !str_starts_with($phone, '0')) {
        $phone = '0' . $phone;
    }
    return $phone;
}

function normalize_phone_for_compare($phone) {
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if ($digits === '') {
        return '';
    }
    return substr($digits, -9);
}

function phones_match($left, $right) {
    $left = normalize_phone_for_compare($left);
    $right = normalize_phone_for_compare($right);

    return $left !== '' && $left === $right;
}

function extract_portal_payer_reference($description) {
    if (preg_match('/\bby\s+([A-Z0-9-]+)\s*$/i', trim((string) $description), $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function fetch_member_sms_profile($conn, $member_id) {
    $stmt = $conn->prepare("
        SELECT
            TRIM(CONCAT_WS(' ',
                NULLIF(TRIM(first_name), ''),
                NULLIF(TRIM(middle_name), ''),
                NULLIF(TRIM(last_name), '')
            )) AS full_name,
            phone,
            crn,
            church_id
        FROM members
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$member) {
        return null;
    }

    if (empty($member['full_name'])) {
        $member['full_name'] = $member['crn'] ?? 'Member';
    }

    return $member;
}

function fetch_member_id_by_phone($conn, $phone) {
    $stmt = $conn->prepare('SELECT id FROM members WHERE phone = ? AND status = "active" LIMIT 1');
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result['id'] ?? null;
}

function fetch_church_sms_name($conn, $church_id) {
    if (empty($church_id)) {
        return 'Freeman Methodist Church - KM';
    }

    $stmt = $conn->prepare('SELECT name FROM churches WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $church_id);
    $stmt->execute();
    $church = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $church['name'] ?? 'Freeman Methodist Church - KM';
}

function fetch_payment_type_name($conn, $payment_type_id) {
    if (empty($payment_type_id)) {
        return '';
    }

    $stmt = $conn->prepare('SELECT name FROM payment_types WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $payment_type_id);
    $stmt->execute();
    $payment_type = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $payment_type['name'] ?? '';
}

function fetch_payment_type_id_by_name($conn, $payment_type_name) {
    $stmt = $conn->prepare('SELECT id FROM payment_types WHERE name = ? AND active = 1 LIMIT 1');
    $stmt->bind_param('s', $payment_type_name);
    $stmt->execute();
    $payment_type = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $payment_type['id'] ?? null;
}

// Log raw input for debugging
$raw_input = file_get_contents('php://input');
file_put_contents(__DIR__.'/../logs/hubtel_callback.log', date('c') . "\n" . $raw_input . "\n", FILE_APPEND);
log_debug('Callback entered');

$data = json_decode($raw_input, true);
log_debug('Callback raw data: ' . json_encode($data));

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
        log_debug('Found PaymentIntent: ' . json_encode($intent));
        $intentModel->updateStatus($conn, $clientReference, $status);
        log_debug("Updated PaymentIntent status to $status");

        if ($hubtelTransactionId && (!isset($intent['hubtel_transaction_id']) || empty($intent['hubtel_transaction_id']) || $intent['hubtel_transaction_id'] === $clientReference)) {
            $update_txn_stmt = $conn->prepare('UPDATE payment_intents SET hubtel_transaction_id = ? WHERE client_reference = ?');
            $update_txn_stmt->bind_param('ss', $hubtelTransactionId, $clientReference);
            $update_txn_stmt->execute();
            $update_txn_stmt->close();
            log_debug("Updated hubtel_transaction_id to: $hubtelTransactionId");
        }

        if ($status === 'Completed') {
            require_once __DIR__.'/../includes/sms.php';
            require_once __DIR__.'/../includes/payment_sms_template.php';

            $paymentsToInsert = [];
            if (!empty($intent['bulk_breakdown'])) {
                $bulk_items = json_decode($intent['bulk_breakdown'], true);
                if (is_array($bulk_items)) {
                    foreach ($bulk_items as $item) {
                        $paymentsToInsert[] = [
                            'member_id' => $item['member_id'] ?? $intent['member_id'],
                            'amount' => $item['amount'],
                            'description' => $item['desc'] ?? $item['typeName'] ?? $intent['description'],
                            'payment_date' => date('Y-m-d H:i:s'),
                            'client_reference' => $clientReference,
                            'status' => $status,
                            'church_id' => $item['church_id'] ?? $intent['church_id'],
                            'payment_type_id' => $item['payment_type_id'] ?? $item['typeId'] ?? null,
                            'payment_period' => $item['payment_period'] ?? $item['period'] ?? null,
                            'payment_period_description' => $item['payment_period_description'] ?? $item['periodText'] ?? null,
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
                log_debug('About to insert payment: ' . json_encode($paymentRow));
                $payment_id = $paymentModel->add($conn, $paymentRow);
                log_debug('Payment add result: ' . var_export($payment_id, true));

                if ($payment_id && is_numeric($payment_id)) {
                    try {
                        $member = fetch_member_sms_profile($conn, (int) $paymentRow['member_id']);
                        log_debug('Member query result: ' . json_encode($member));

                        if ($member && !empty($member['phone'])) {
                            $church_name = fetch_church_sms_name($conn, (int) $paymentRow['church_id']);
                            $payment_type_name = fetch_payment_type_name($conn, (int) $paymentRow['payment_type_id']);
                            $harvest_year = null;
                            $harvest_total = null;

                            if (is_harvest_payment_type($payment_type_name)) {
                                $harvest_year = get_payment_period_year(
                                    $paymentRow['payment_period'],
                                    $paymentRow['payment_period_description'],
                                    $paymentRow['payment_date']
                                );
                                $harvest_total = get_member_yearly_harvest_total(
                                    $conn,
                                    (int) $paymentRow['member_id'],
                                    $harvest_year,
                                    (int) $paymentRow['payment_type_id']
                                );
                            }

                            $payer_reference = '';
                            $portal_description = $paymentRow['description'] ?? ($intent['description'] ?? '');
                            $portal_payer_reference = extract_portal_payer_reference($portal_description);
                            $customer_name = normalize_payment_sms_value($intent['customer_name'] ?? '');
                            $is_self_portal_payment = false;
                            if (!empty($intent['customer_phone']) && phones_match($intent['customer_phone'], $member['phone'])) {
                                $is_self_portal_payment = true;
                            } elseif ($portal_payer_reference !== '' && !empty($member['crn']) && strcasecmp($portal_payer_reference, $member['crn']) === 0) {
                                $is_self_portal_payment = true;
                            } elseif ($customer_name !== '' && strcasecmp($customer_name, normalize_payment_sms_value($member['full_name'])) === 0) {
                                $is_self_portal_payment = true;
                            }

                            if (!$is_self_portal_payment) {
                                $payer_reference = $portal_payer_reference !== '' ? $portal_payer_reference : $customer_name;
                            }

                            $sms_message = build_hubtel_portal_payment_sms(
                                $member['full_name'],
                                $paymentRow['amount'],
                                $paymentRow['payment_period_description'],
                                $payment_type_name,
                                $payer_reference,
                                $church_name,
                                $harvest_year,
                                $harvest_total,
                                $paymentRow['payment_period'],
                                $paymentRow['payment_date']
                            );

                            log_debug('About to send SMS: ' . $sms_message);
                            $sms_result = log_sms($member['phone'], $sms_message, $payment_id, 'hubtel_portal_payment');
                            log_debug('SMS sent for payment ID ' . $payment_id . ': ' . json_encode($sms_result));
                        } else {
                            log_debug('Member has no phone or member not found');
                        }
                    } catch (Exception $e) {
                        log_debug('SMS error for payment: ' . $e->getMessage());
                    }
                } else {
                    log_debug('SMS trigger condition NOT met - no payment ID returned or result is false');
                }
            }
        }
    } else {
        log_debug('No PaymentIntent found for clientReference - checking for legacy Hubtel payment');

        if ($status === 'Completed') {
            require_once __DIR__.'/../includes/sms.php';
            require_once __DIR__.'/../includes/payment_sms_template.php';

            $amount = (float) ($data['Data']['Amount'] ?? 0);
            $description = $data['Data']['Description'] ?? 'Hubtel Payment';
            $customer_phone = normalize_callback_phone($data['Data']['CustomerMobileNumber'] ?? '');
            $customer_name = normalize_payment_sms_value($data['Data']['CustomerName'] ?? '');

            if ($amount > 0 && $customer_phone !== '') {
                $target_member_id = null;
                $payer_member_id = null;
                $payment_period = null;
                $payment_period_description = null;
                $payment_type_name = '';

                if (preg_match('/Target ID:\s*(\d+)/', $description, $target_matches)) {
                    $target_member_id = (int) $target_matches[1];
                }

                if (preg_match('/Payer ID:\s*(\d+)/', $description, $payer_matches)) {
                    $payer_member_id = (int) $payer_matches[1];
                }

                if (preg_match('/Member ID:\s*(\d+)/', $description, $member_matches)) {
                    $target_member_id = (int) $member_matches[1];
                }

                if (preg_match('/Period:\s*([0-9-]+)/', $description, $period_matches)) {
                    $payment_period = $period_matches[1];
                    $payment_period_description = date('F Y', strtotime($payment_period));
                }

                if (preg_match('/^([^-]+?)\s*-/', $description, $type_matches)) {
                    $payment_type_name = trim($type_matches[1]);
                }

                $payment_type_id = $payment_type_name !== '' ? fetch_payment_type_id_by_name($conn, $payment_type_name) : null;
                $final_member_id = $target_member_id ?: $payer_member_id ?: fetch_member_id_by_phone($conn, $customer_phone);

                if ($final_member_id) {
                    $member = fetch_member_sms_profile($conn, (int) $final_member_id);
                    if ($member) {
                        $church_id = $member['church_id'] ?? null;
                        $payment_data = [
                            'member_id' => (int) $final_member_id,
                            'amount' => $amount,
                            'description' => $description,
                            'payment_date' => date('Y-m-d H:i:s'),
                            'client_reference' => $clientReference,
                            'status' => $status,
                            'church_id' => $church_id,
                            'payment_type_id' => $payment_type_id,
                            'payment_period' => $payment_period,
                            'payment_period_description' => $payment_period_description,
                            'recorded_by' => 'USSD Payment',
                            'mode' => 'Mobile Money'
                        ];

                        log_debug('Recording fallback Hubtel payment: ' . json_encode($payment_data));
                        $payment_id = $paymentModel->add($conn, $payment_data);

                        if ($payment_id && is_numeric($payment_id) && !empty($member['phone'])) {
                            $church_name = fetch_church_sms_name($conn, $church_id);
                            if ($payment_type_name === '' && !empty($payment_type_id)) {
                                $payment_type_name = fetch_payment_type_name($conn, (int) $payment_type_id);
                            }

                            if ($customer_name === '' && !empty($payer_member_id) && $payer_member_id !== (int) $final_member_id) {
                                $payer_member = fetch_member_sms_profile($conn, (int) $payer_member_id);
                                $customer_name = $payer_member['full_name'] ?? '';
                            }
                            if ($customer_name === '') {
                                $customer_name = $member['full_name'];
                            }

                            $show_by_sender = (!empty($target_member_id) && empty($payer_member_id))
                                || (!empty($payer_member_id) && (int) $payer_member_id !== (int) $final_member_id)
                                || (!empty($customer_phone) && !phones_match($customer_phone, $member['phone']));
                            $sender_name_for_message = $show_by_sender ? $customer_name : '';

                            $harvest_year = null;
                            $harvest_total = null;
                            if (is_harvest_payment_type($payment_type_name)) {
                                $harvest_year = get_payment_period_year($payment_period, $payment_period_description, $payment_data['payment_date']);
                                $harvest_total = get_member_yearly_harvest_total(
                                    $conn,
                                    (int) $final_member_id,
                                    $harvest_year,
                                    (int) $payment_type_id
                                );
                            }

                            $sms_message = build_hubtel_ussd_member_payment_sms(
                                $member['full_name'],
                                $amount,
                                $payment_period_description,
                                $payment_type_name,
                                $sender_name_for_message,
                                $church_name,
                                $harvest_year,
                                $harvest_total,
                                $payment_period,
                                $payment_data['payment_date']
                            );

                            $sms_result = log_sms($member['phone'], $sms_message, $payment_id, 'hubtel_ussd_payment');
                            log_debug('Fallback Hubtel SMS sent: ' . json_encode($sms_result));
                        }
                    }
                } else {
                    log_debug('Could not identify member for fallback Hubtel payment');
                }
            } else {
                log_debug('Invalid fallback Hubtel payment data - missing amount or phone');
            }
        }
    }
} else {
    log_debug('No clientReference in callback data');
}

http_response_code(200);
echo 'OK';
