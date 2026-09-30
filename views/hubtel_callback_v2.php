<?php

// Hubtel checkout callback. A definitive success posts immediately. Failed or
// uncertain results post nothing; a later status-check success requires review.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/PaymentGatewayCallbackService.php';
require_once __DIR__ . '/../services/OnlinePaymentApprovalService.php';

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
    $service = new PaymentGatewayCallbackService($conn);
    $result = $service->record([
        'client_reference' => $reference,
        'status' => $status,
        'amount' => (float) ($data['amount'] ?? $data['Amount'] ?? 0),
        'transaction_id' => $data['transactionId'] ?? $data['TransactionId'] ?? null,
        'description' => $data['description'] ?? $data['Description'] ?? 'Hubtel online payment',
        'customer_name' => $data['customerName'] ?? $data['CustomerName'] ?? 'Hubtel customer',
        'customer_phone' => $data['customerPhone'] ?? $data['CustomerMobileNumber'] ?? '',
        'payment_source' => 'online_checkout',
        'raw_payload' => $rawInput,
    ]);
    if (($result['status'] ?? '') === 'Completed'
        && ($result['previous_status'] ?? null) !== 'Failed'
        && ($result['confirmation_source'] ?? null) === 'gateway_callback') {
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
