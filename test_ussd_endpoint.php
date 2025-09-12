<?php
// Simple test endpoint to verify USSD calls are reaching the server
header('Content-Type: application/json');

$log_file = __DIR__.'/logs/ussd_test.log';
$raw_input = file_get_contents('php://input');
$timestamp = date('Y-m-d H:i:s');

// Log all incoming requests
file_put_contents($log_file, "[$timestamp] USSD Test Endpoint Called\n", FILE_APPEND);
file_put_contents($log_file, "Method: " . $_SERVER['REQUEST_METHOD'] . "\n", FILE_APPEND);
file_put_contents($log_file, "Raw Input: " . $raw_input . "\n", FILE_APPEND);
file_put_contents($log_file, "GET Data: " . json_encode($_GET) . "\n", FILE_APPEND);
file_put_contents($log_file, "POST Data: " . json_encode($_POST) . "\n", FILE_APPEND);
file_put_contents($log_file, "Headers: " . json_encode(getallheaders()) . "\n", FILE_APPEND);
file_put_contents($log_file, "---\n", FILE_APPEND);

// Return a simple USSD response
echo json_encode([
    'SessionId' => 'test-session',
    'Type' => 'response',
    'Message' => 'USSD Test Endpoint Working! Time: ' . $timestamp,
    'Label' => 'Test Response',
    'ClientState' => 'test',
    'DataType' => 'display',
    'FieldType' => 'text'
]);
?>
