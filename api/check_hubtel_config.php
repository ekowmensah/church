<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Check Hubtel configuration
$config_check = [
    'timestamp' => date('Y-m-d H:i:s'),
    'environment_variables' => [
        'HUBTEL_API_KEY' => [
            'getenv' => getenv('HUBTEL_API_KEY') ? 'SET (length: ' . strlen(getenv('HUBTEL_API_KEY')) . ')' : 'NOT SET',
            'env_array' => isset($_ENV['HUBTEL_API_KEY']) ? 'SET (length: ' . strlen($_ENV['HUBTEL_API_KEY']) . ')' : 'NOT SET',
            'server_array' => isset($_SERVER['HUBTEL_API_KEY']) ? 'SET (length: ' . strlen($_SERVER['HUBTEL_API_KEY']) . ')' : 'NOT SET'
        ],
        'HUBTEL_API_SECRET' => [
            'getenv' => getenv('HUBTEL_API_SECRET') ? 'SET (length: ' . strlen(getenv('HUBTEL_API_SECRET')) . ')' : 'NOT SET',
            'env_array' => isset($_ENV['HUBTEL_API_SECRET']) ? 'SET (length: ' . strlen($_ENV['HUBTEL_API_SECRET']) . ')' : 'NOT SET',
            'server_array' => isset($_SERVER['HUBTEL_API_SECRET']) ? 'SET (length: ' . strlen($_SERVER['HUBTEL_API_SECRET']) . ')' : 'NOT SET'
        ],
        'HUBTEL_MERCHANT_ACCOUNT' => [
            'getenv' => getenv('HUBTEL_MERCHANT_ACCOUNT') ?: 'NOT SET',
            'env_array' => $_ENV['HUBTEL_MERCHANT_ACCOUNT'] ?? 'NOT SET',
            'server_array' => $_SERVER['HUBTEL_MERCHANT_ACCOUNT'] ?? 'NOT SET'
        ]
    ],
    'constants' => [
        'HUBTEL_API_KEY' => defined('HUBTEL_API_KEY') ? 'DEFINED' : 'NOT DEFINED',
        'HUBTEL_API_SECRET' => defined('HUBTEL_API_SECRET') ? 'DEFINED' : 'NOT DEFINED',
        'HUBTEL_MERCHANT_ACCOUNT' => defined('HUBTEL_MERCHANT_ACCOUNT') ? 'DEFINED' : 'NOT DEFINED'
    ],
    'files_exist' => [
        '.env' => file_exists(__DIR__ . '/../.env') ? 'EXISTS' : 'NOT FOUND',
        'vendor/autoload.php' => file_exists(__DIR__ . '/../vendor/autoload.php') ? 'EXISTS' : 'NOT FOUND',
        'hubtel_payment_v2.php' => file_exists(__DIR__ . '/../helpers/hubtel_payment_v2.php') ? 'EXISTS' : 'NOT FOUND'
    ]
];

// Try to load .env manually if it exists
if (file_exists(__DIR__ . '/../.env')) {
    $env_content = file_get_contents(__DIR__ . '/../.env');
    $config_check['env_file_sample'] = substr($env_content, 0, 200) . '...';
    
    // Parse .env manually
    $lines = explode("\n", $env_content);
    $env_vars = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1], '"\'');
            if (strpos($key, 'HUBTEL') === 0) {
                $env_vars[$key] = strlen($value) > 0 ? 'SET (length: ' . strlen($value) . ')' : 'EMPTY';
            }
        }
    }
    $config_check['env_file_parsed'] = $env_vars;
}

echo json_encode($config_check, JSON_PRETTY_PRINT);
?>
