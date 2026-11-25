<?php

// Hubtel checkout callback. A definitive success posts immediately. Failed or
// uncertain results post nothing; a later status-check success requires review.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/PaymentGatewayCallbackService.php';
require_once __DIR__ . '/../services/OnlinePaymentApprovalService.php';
require_once __DIR__ . '/../helpers/hubtel_status.php';

$debugLog = __DIR__ . '/../logs/hubtel_callback_debug.log';
$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

// Hubtel has emitted both a nested {Data:{...}} callback and a flat callback
// over the lifetime of this integration. Some accounts also use lowercase
// keys or form encoding. Normalize those transport variants before applying
// the single verified posting workflow.
if (!is_array($payload) && !empty($_POST)) {
    $payload = $_POST;
}
$data = [];
if (is_array($payload)) {
    if (is_array($payload['Data'] ?? null)) {
        $data = $payload['Data'];
    } elseif (is_array($payload['data'] ?? null)) {
        $data = $payload['data'];
    } elseif (is_string($payload['Data'] ?? null)) {
        $decodedData = json_decode($payload['Data'], true);
        $data = is_array($decodedData) ? $decodedData : [];
    } elseif (is_string($payload['data'] ?? null)) {
        $decodedData = json_decode($payload['data'], true);
        $data = is_array($decodedData) ? $decodedData : [];
    } else {
        $data = $payload;
    }
}

$status = trim((string) ($data['Status'] ?? $data['status'] ?? $data['TransactionStatus'] ?? $data['transactionStatus'] ?? ''));
if (!$data || $status === '') {
    file_put_contents($debugLog, date('c') . " Rejected callback: missing payload or status.\n", FILE_APPEND);
    http_response_code(400);
    exit('Invalid callback');
}

$reference = trim((string) ($data['ClientReference'] ?? $data['clientReference'] ?? $data['Reference'] ?? $data['reference'] ?? ''));
if ($reference === '') {
    file_put_contents($debugLog, date('c') . " Rejected callback: missing client reference.\n", FILE_APPEND);
    http_response_code(400);
    exit('Missing client reference');
}

try {
    $intentStmt = $conn->prepare('SELECT amount, hubtel_transaction_id, transaction_id FROM payment_intents WHERE client_reference = ? LIMIT 1');
    $intentStmt->bind_param('s', $reference);
    $intentStmt->execute();
    $existingIntent = $intentStmt->get_result()->fetch_assoc();
    $intentStmt->close();

    $callbackStatus = PaymentGatewayCallbackService::normalizeStatus($status);
    $callbackAmount = (float) ($data['Amount'] ?? $data['amount'] ?? $data['AmountPaid'] ?? $data['amountPaid'] ?? 0);
    $callbackTransactionId = $data['TransactionId']
        ?? $data['transactionId']
        ?? $data['HubtelTransactionId']
        ?? $data['hubtelTransactionId']
        ?? null;
    $verificationStatus = 'not_checked';
    if ($callbackStatus === 'Completed') {
        $verification = verify_hubtel_transaction_status(
            $callbackTransactionId ?: ($existingIntent['hubtel_transaction_id'] ?? $reference),
            $reference,
            (float) ($existingIntent['amount'] ?? $callbackAmount)
        );
        $verificationStatus = (string) ($verification['verification_status'] ?? 'not_checked');
        if (!empty($verification['verified'])) {
            $callbackStatus = (string) ($verification['status'] ?? $callbackStatus);
            $callbackAmount = (float) $verification['amount'];
            $callbackTransactionId = $verification['transaction_id'] ?? $callbackTransactionId;
        }
    }

    $memberId = null;
    $churchId = null;
    $paymentTypeId = null;
    $paymentPeriod = null;
    $periodDescription = null;
    $description = trim((string) ($data['Description'] ?? $data['description'] ?? 'Hubtel payment'));

    if (preg_match('/(?:Target|Member) ID:\s*(\d+)/i', $description, $matches)) {
        $memberId = (int) $matches[1];
    }
    if ($memberId === null) {
        $phone = preg_replace('/\D+/', '', (string) (
            $data['CustomerMobileNumber']
            ?? $data['customerMobileNumber']
            ?? $data['CustomerPhone']
            ?? $data['customerPhone']
            ?? ''
        ));
        $phone = strlen($phone) >= 9 ? substr($phone, -9) : $phone;
        if ($phone !== '') {
            $stmt = $conn->prepare(
                "SELECT id, church_id FROM members
                  WHERE RIGHT(REGEXP_REPLACE(COALESCE(phone,''), '[^0-9]', ''), 9) = ?
                    AND status = 'active' LIMIT 1"
            );
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $member = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($member) {
                $memberId = (int) $member['id'];
                $churchId = (int) $member['church_id'];
            }
        }
    }
    if ($memberId !== null && $churchId === null) {
        $stmt = $conn->prepare('SELECT church_id FROM members WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0) ?: null;
        $stmt->close();
    }
    if (preg_match('/Period:\s*([0-9-]+)/i', $description, $matches)) {
        $paymentPeriod = $matches[1];
        $periodDescription = strtotime($paymentPeriod) ? date('F Y', strtotime($paymentPeriod)) : null;
    }
    if (preg_match('/^([^-]+?)\s*-/', $description, $matches)) {
        $typeName = trim($matches[1]);
        $stmt = $conn->prepare('SELECT id FROM payment_types WHERE name = ? AND active = 1 LIMIT 1');
        $stmt->bind_param('s', $typeName);
        $stmt->execute();
        $paymentTypeId = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0) ?: null;
        $stmt->close();
    }

    $service = new PaymentGatewayCallbackService($conn);
    $result = $service->record([
        'client_reference' => $reference,
        'status' => $callbackStatus,
        'amount' => $callbackAmount,
        'transaction_id' => $callbackTransactionId,
        'description' => $description,
        'customer_name' => $data['CustomerName'] ?? $data['customerName'] ?? 'Hubtel customer',
        'customer_phone' => $data['CustomerMobileNumber']
            ?? $data['customerMobileNumber']
            ?? $data['CustomerPhone']
            ?? $data['customerPhone']
            ?? '',
        'member_id' => $memberId,
        'church_id' => $churchId,
        'payment_type_id' => $paymentTypeId,
        'payment_period' => $paymentPeriod,
        'payment_period_description' => $periodDescription,
        'payment_source' => 'online_checkout',
        'gateway_verification_status' => $verificationStatus,
        'raw_payload' => $rawInput,
    ]);
    if (($result['status'] ?? '') === 'Completed'
        && ($result['previous_status'] ?? null) !== 'Failed'
        && ($result['confirmation_source'] ?? null) === 'gateway_callback'
        && ($result['gateway_verification_status'] ?? null) === 'verified'
        && !empty($result['locally_initialized'])) {
        $postingService = new OnlinePaymentApprovalService($conn, 0, true, true);
        $posting = $postingService->autoPostDefinitiveGatewayPayment(
            (int) $result['intent_id'],
            ['online_checkout'],
            'Automatically posted from a definitive Hubtel online-checkout callback.'
        );
        $result['posting'] = $posting;
    }
    file_put_contents($debugLog, date('c') . ' Captured: ' . json_encode($result) . "\n", FILE_APPEND);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $error) {
    file_put_contents($debugLog, date('c') . ' Error: ' . $error->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo 'Capture failed';
}
