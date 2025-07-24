<?php
// helpers/hubtel_payment.php
// Hubtel payment integration utility
// Usage: include this file and call create_hubtel_checkout($params)

function create_hubtel_checkout($params) {
    // Required params: amount, description, callbackUrl, returnUrl, customerName, customerPhone, clientReference
    $api_key = getenv('HUBTEL_API_KEY') ?: (defined('HUBTEL_API_KEY') ? HUBTEL_API_KEY : null);
    $api_secret = getenv('HUBTEL_API_SECRET') ?: (defined('HUBTEL_API_SECRET') ? HUBTEL_API_SECRET : null);
    $merchant_account = getenv('HUBTEL_MERCHANT_ACCOUNT') ?: (defined('HUBTEL_MERCHANT_ACCOUNT') ? HUBTEL_MERCHANT_ACCOUNT : null);
    if (!$api_key || !$api_secret || !$merchant_account) {
        return ['success' => false, 'error' => 'Hubtel API credentials not set'];
    }
    $url = 'https://api.hubtel.com/v1/merchantaccount/onlinecheckout/invoice/create';
    $data = [
        'amount' => $params['amount'],
        'description' => $params['description'],
        'callbackUrl' => $params['callbackUrl'],
        'returnUrl' => $params['returnUrl'],
        'merchantAccountNumber' => $merchant_account,
        'clientReference' => $params['clientReference'],
        'customerName' => $params['customerName'],
        'customerPhoneNumber' => $params['customerPhone'],
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($api_key . ':' . $api_secret)
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'error' => $error];
    }
    curl_close($ch);
    $result = json_decode($response, true);
    if ($http_code == 200 && isset($result['data']['checkoutUrl'])) {
        return ['success' => true, 'checkoutUrl' => $result['data']['checkoutUrl'], 'invoiceToken' => $result['data']['invoiceToken']];
    } else {
        return ['success' => false, 'error' => $result['message'] ?? 'Unknown error', 'response' => $result];
    }
}
