<?php

// Hubtel checkout callback. A definitive success posts immediately. Failed or
// uncertain results post nothing; a later status-check success requires review.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/PaymentGatewayCallbackService.php';
require_once __DIR__ . '/../services/OnlinePaymentApprovalService.php';

$debugLog = __DIR__ . '/../logs/hubtel_callback_debug.log';
$rawInput = file_get_contents('php://input');
file_put_contents(__DIR__ . '/../logs/hubtel_callback.log', date('c') . "\n" . $rawInput . "\n", FILE_APPEND);
$payload = json_decode($rawInput, true);
$data = is_array($payload['Data'] ?? null) ? $payload['Data'] : [];
if (!$data || trim((string) ($data['Status'] ?? '')) === '') {
    http_response_code(400);
    exit('Invalid callback');
}

$reference = trim((string) ($data['ClientReference'] ?? ''));
if ($reference === '') {
    http_response_code(400);
    exit('Missing client reference');
}

try {
    $memberId = null;
    $churchId = null;
    $paymentTypeId = null;
    $paymentPeriod = null;
    $periodDescription = null;
    $description = trim((string) ($data['Description'] ?? 'Hubtel payment'));

    if (preg_match('/(?:Target|Member) ID:\s*(\d+)/i', $description, $matches)) {
        $memberId = (int) $matches[1];
    }
    if ($memberId === null) {
        $phone = preg_replace('/\D+/', '', (string) ($data['CustomerMobileNumber'] ?? ''));
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
        'status' => (string) $data['Status'],
        'amount' => (float) ($data['Amount'] ?? 0),
        'transaction_id' => $data['TransactionId'] ?? $data['transactionId'] ?? null,
        'description' => $description,
        'customer_name' => $data['CustomerName'] ?? 'Hubtel customer',
        'customer_phone' => $data['CustomerMobileNumber'] ?? '',
        'member_id' => $memberId,
        'church_id' => $churchId,
        'payment_type_id' => $paymentTypeId,
        'payment_period' => $paymentPeriod,
        'payment_period_description' => $periodDescription,
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
    file_put_contents($debugLog, date('c') . ' Captured: ' . json_encode($result) . "\n", FILE_APPEND);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $error) {
    file_put_contents($debugLog, date('c') . ' Error: ' . $error->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo 'Capture failed';
}
