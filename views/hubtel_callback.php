<?php
// Hubtel callback handler: receives payment status update from Hubtel
// This is the endpoint you configure in Hubtel dashboard as the callbackUrl
require_once __DIR__.'/../config/config.php';

// Log raw input for debugging
file_put_contents(__DIR__.'/../logs/hubtel_callback.log', date('c')."\n".file_get_contents('php://input')."\n", FILE_APPEND);

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['Data']['Status'])) {
    http_response_code(400);
    echo 'Invalid callback';
    exit;
}

$status = $data['Data']['Status']; // e.g. 'Success', 'Failed', 'Cancelled', etc.
$invoiceToken = $data['Data']['InvoiceToken'] ?? '';
$amount = $data['Data']['Amount'] ?? 0;
$clientReference = $data['Data']['ClientReference'] ?? '';

// TODO: Mark payment as complete/failed in your DB using $invoiceToken or $clientReference
// Example: update payments set status = $status where invoice_token = $invoiceToken

// Respond OK to Hubtel
http_response_code(200);
echo 'OK';
