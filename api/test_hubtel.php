<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/hubtel_payment_v2.php';

try {
    // Test Hubtel integration with sample data
    $test_params = [
        'amount' => 10.00,
        'description' => 'Test Payment - PWA Integration',
        'callbackUrl' => 'https://portal.myfreeman.org/church/api/hubtel_shortcode_webhook.php',
        'returnUrl' => 'https://portal.myfreeman.org/church/pwa/index.html#payment-success',
        'cancellationUrl' => 'https://portal.myfreeman.org/church/pwa/index.html#payment-cancelled',
        'customerName' => 'Test User',
        'customerPhone' => '233241234567',
        'customerEmail' => 'test@example.com',
        'clientReference' => 'TEST_' . date('YmdHis') . rand(1000, 9999)
    ];
    
    echo json_encode([
        'test_params' => $test_params,
        'hubtel_result' => create_hubtel_checkout_v2($test_params)
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>
