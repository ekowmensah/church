<?php

// Hubtel checkout callback. A definitive success posts immediately. Failed or
// uncertain results post nothing; a later status-check success requires review.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/PaymentGatewayCallbackService.php';
require_once __DIR__ . '/../services/OnlinePaymentApprovalService.php';
require_once __DIR__ . '/../helpers/hubtel_status.php';

$logFile = __DIR__ . '/../logs/hubtel_callback_v2.log';
$rawInput = file_get_contents('php://input');
file_put_contents($logFile, date('c') . "\n" . $rawInput . "\n", FILE_APPEND);
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit('Invalid callback data');
}

$data = isset($payload['Data']) && is_array($payload['Data']) ? $payload['Data'] : $payload;
$reference = trim((string) ($data['clientReference'] ?? $data['ClientReference'] ?? ''));
$status = trim((string) ($data['status'] ?? $data['Status'] ?? ''));
if ($reference === '' || $status === '') {
    http_response_code(400);
    exit('Missing required fields');
}

try {
    $intentStmt = $conn->prepare('SELECT amount, hubtel_transaction_id, transaction_id FROM payment_intents WHERE client_reference = ? LIMIT 1');
    $intentStmt->bind_param('s', $reference);
    $intentStmt->execute();
    $existingIntent = $intentStmt->get_result()->fetch_assoc();
    $intentStmt->close();
    $callbackStatus = PaymentGatewayCallbackService::normalizeStatus($status);
    $callbackAmount = (float) ($data['amount'] ?? $data['Amount'] ?? 0);
    $callbackTransactionId = $data['transactionId'] ?? $data['TransactionId'] ?? null;
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

    $service = new PaymentGatewayCallbackService($conn);
    $result = $service->record([
        'client_reference' => $reference,
        'status' => $callbackStatus,
        'amount' => $callbackAmount,
        'transaction_id' => $callbackTransactionId,
        'description' => $data['description'] ?? $data['Description'] ?? 'Hubtel online payment',
        'customer_name' => $data['customerName'] ?? $data['CustomerName'] ?? 'Hubtel customer',
        'customer_phone' => $data['customerPhone'] ?? $data['CustomerMobileNumber'] ?? '',
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
    file_put_contents($logFile, date('c') . ' Captured: ' . json_encode($result) . "\n", FILE_APPEND);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $error) {
    file_put_contents($logFile, date('c') . ' Error: ' . $error->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo 'Capture failed';
}
