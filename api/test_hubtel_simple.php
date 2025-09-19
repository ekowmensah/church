<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo "<h2>Hubtel Integration Test</h2>";

// Check if .env file exists
$env_file = __DIR__ . '/../.env';
echo "<p><strong>.env file:</strong> " . (file_exists($env_file) ? "EXISTS" : "NOT FOUND") . "</p>";

// Try to load environment variables
if (file_exists($env_file)) {
    $env_content = file_get_contents($env_file);
    $lines = explode("\n", $env_content);
    
    echo "<h3>Environment Variables:</h3>";
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1], '"\'');
            
            if (strpos($key, 'HUBTEL') === 0) {
                echo "<p><strong>$key:</strong> " . (strlen($value) > 0 ? "SET (length: " . strlen($value) . ")" : "EMPTY") . "</p>";
            }
        }
    }
}

// Test the Hubtel helper
echo "<h3>Testing Hubtel Helper:</h3>";
try {
    require_once __DIR__ . '/../helpers/hubtel_payment_v2.php';
    
    $test_params = [
        'amount' => 1.00,
        'description' => 'Test Payment',
        'callbackUrl' => 'https://portal.myfreeman.org/church/api/hubtel_shortcode_webhook.php',
        'returnUrl' => 'https://portal.myfreeman.org/church/pwa/index.html',
        'customerName' => 'Test User',
        'customerPhone' => '233241234567',
        'clientReference' => 'TEST_' . time()
    ];
    
    $result = create_hubtel_checkout_v2($test_params);
    
    echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
