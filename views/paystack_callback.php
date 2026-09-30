<?php

// Paystack return handler. A definitive server-verified success posts
// immediately. A success discovered only after an earlier failure stays in
// the approval queue for an authorized decision.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/PaymentGatewayCallbackService.php';
require_once __DIR__ . '/../services/OnlinePaymentApprovalService.php';

$reference = trim((string) ($_GET['reference'] ?? ''));
if ($reference === '') {
    http_response_code(400);
    exit('No payment reference was supplied.');
}

$secretKey = defined('PAYSTACK_SECRET_KEY') ? trim((string) PAYSTACK_SECRET_KEY) : '';
if ($secretKey === '') {
    error_log('Paystack callback cannot verify payment: PAYSTACK_SECRET_KEY is missing.');
    http_response_code(503);
    exit('Payment verification is temporarily unavailable.');
}

$ch = curl_init('https://api.paystack.co/transaction/verify/' . rawurlencode($reference));
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$rawResponse = curl_exec($ch);
$curlError = curl_error($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError !== '' || !is_string($rawResponse) || $rawResponse === '') {
    error_log('Paystack verification transport error: ' . $curlError);
    http_response_code(502);
    exit('Payment verification failed. Please retry shortly.');
}

$response = json_decode($rawResponse, true);
if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($response) || empty($response['status'])) {
    error_log('Paystack verification rejected: ' . $rawResponse);
    http_response_code(502);
    exit('Paystack could not verify this transaction.');
}

$transaction = is_array($response['data'] ?? null) ? $response['data'] : [];
$metadata = is_array($transaction['metadata'] ?? null) ? $transaction['metadata'] : [];
$verifiedReference = trim((string) ($transaction['reference'] ?? $reference));
if (!hash_equals($reference, $verifiedReference)) {
    error_log('Paystack verification reference mismatch.');
    http_response_code(409);
    exit('The verified payment reference did not match the request.');
}

$bulkItems = is_array($metadata['bulk_items'] ?? null) ? $metadata['bulk_items'] : null;
$customer = is_array($transaction['customer'] ?? null) ? $transaction['customer'] : [];
$customerName = trim((string) ($metadata['name'] ?? ''));
if ($customerName === '') {
    $customerName = trim(implode(' ', array_filter([
        $customer['first_name'] ?? null,
        $customer['last_name'] ?? null,
    ])));
}

try {
    $service = new PaymentGatewayCallbackService($conn);
    $capture = $service->record([
        'client_reference' => $verifiedReference,
        'transaction_id' => $transaction['id'] ?? null,
        'status' => $transaction['status'] ?? 'pending',
        'amount' => ((float) ($transaction['amount'] ?? 0)) / 100,
        'description' => $metadata['description'] ?? 'Paystack online payment',
        'customer_name' => $customerName ?: 'Paystack customer',
        'customer_phone' => $metadata['phone'] ?? $customer['phone'] ?? '',
        'member_id' => $metadata['member_id'] ?? null,
        'church_id' => $metadata['church_id'] ?? null,
        'payment_type_id' => $metadata['payment_type_id'] ?? null,
        'payment_period' => $metadata['payment_period'] ?? null,
        'payment_period_description' => $metadata['payment_period_description'] ?? null,
        'bulk_breakdown' => $bulkItems,
        'payment_source' => 'paystack',
        'raw_payload' => $rawResponse,
    ]);

    if (($capture['status'] ?? '') === 'Completed'
        && ($capture['previous_status'] ?? null) !== 'Failed'
        && ($capture['confirmation_source'] ?? null) === 'gateway_callback') {
        $postingService = new OnlinePaymentApprovalService($conn, 0, true, true);
        $posting = $postingService->autoPostDefinitiveGatewayPayment(
            (int) $capture['intent_id'],
            ['paystack'],
            'Automatically posted after definitive Paystack server verification.'
        );
        echo '<h2>Payment confirmed</h2><p>Your verified transaction has been posted. Reference: '
            . htmlspecialchars($verifiedReference, ENT_QUOTES, 'UTF-8') . '.</p>';
    } elseif ($capture['approval_status'] === 'pending') {
        echo '<h2>Payment received</h2><p>Your transaction was verified and is awaiting authorized posting. Reference: '
            . htmlspecialchars($verifiedReference, ENT_QUOTES, 'UTF-8') . '.</p>';
    } elseif ($capture['approval_status'] === 'approved') {
        echo '<h2>Payment confirmed</h2><p>This transaction has already been approved and posted.</p>';
    } else {
        echo '<h2>Payment status received</h2><p>Status: '
            . htmlspecialchars((string) $capture['status'], ENT_QUOTES, 'UTF-8') . '.</p>';
    }
} catch (Throwable $error) {
    error_log('Paystack verified-payment capture failed: ' . $error->getMessage());
    http_response_code(500);
    echo '<h2>Payment verified but not captured</h2><p>Please retry this page or contact an administrator with reference '
        . htmlspecialchars($verifiedReference, ENT_QUOTES, 'UTF-8') . '.</p>';
}
