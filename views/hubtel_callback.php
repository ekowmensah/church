<?php
// Hubtel callback handler: receives payment status update from Hubtel
// This is the endpoint you configure in Hubtel dashboard as the callbackUrl
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../models/Payment.php';

// Log raw input for debugging
file_put_contents(__DIR__.'/../logs/hubtel_callback.log', date('c')."\n".file_get_contents('php://input')."\n", FILE_APPEND);

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['Data']['Status'])) {
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
    $intent = $intentModel->getByReference($conn, $clientReference);
    if ($intent) {
        $intentModel->updateStatus($conn, $clientReference, $status);
        if ($status === 'Completed') {
            if (!empty($intent['bulk_breakdown'])) {
                $bulk_items = json_decode($intent['bulk_breakdown'], true);
                if (is_array($bulk_items)) {
                    foreach ($bulk_items as $item) {
                        $paymentModel->add($conn, [
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
                        ]);
                    }
                }
            } else {
                $paymentModel->add($conn, [
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
            }
        }
    }
}

// Respond OK to Hubtel
http_response_code(200);
echo 'OK';
