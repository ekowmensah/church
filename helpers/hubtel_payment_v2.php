<?php
/**
 * Hubtel Payment Integration Helper V2
 * Uses the new payproxyapi.hubtel.com/items/initiate endpoint
 */

// Load environment variables if not already loaded
if (!function_exists('getenv') || !getenv('HUBTEL_API_KEY')) {
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
        $dotenv = Dotenv\Dotenv::createMutable(__DIR__ . '/..');
        $dotenv->safeLoad();
    }
}

// Define constants if not already defined
if (!defined('HUBTEL_API_KEY') && getenv('HUBTEL_API_KEY')) {
    define('HUBTEL_API_KEY', getenv('HUBTEL_API_KEY'));
}
if (!defined('HUBTEL_API_SECRET') && getenv('HUBTEL_API_SECRET')) {
    define('HUBTEL_API_SECRET', getenv('HUBTEL_API_SECRET'));
}
if (!defined('HUBTEL_MERCHANT_ACCOUNT') && getenv('HUBTEL_MERCHANT_ACCOUNT')) {
    define('HUBTEL_MERCHANT_ACCOUNT', getenv('HUBTEL_MERCHANT_ACCOUNT'));
}

// Usage: include this file and call create_hubtel_checkout_v2($params)

/**
 * Create a Hubtel checkout using the new payproxyapi.hubtel.com/items/initiate endpoint
 * 
 * @param array $params Parameters for the checkout
 * @return array Response with success status and checkout URL or error details
 */
function create_hubtel_checkout_v2($params) {
    // Helper to read env from multiple sources reliably
    $readEnv = function ($key, $constFallback = null) {
        $val = getenv($key);
        if ($val === false || $val === null || $val === '') {
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') $val = $_ENV[$key];
            elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') $val = $_SERVER[$key];
            elseif ($constFallback && defined($constFallback)) $val = constant($constFallback);
        }
        return ($val === false || $val === '') ? null : $val;
    };

    // Get API credentials from environment variables or constants
    $api_key = $readEnv('HUBTEL_API_KEY', 'HUBTEL_API_KEY');
    $api_secret = $readEnv('HUBTEL_API_SECRET', 'HUBTEL_API_SECRET');
    $merchant_account = $readEnv('HUBTEL_MERCHANT_ACCOUNT', 'HUBTEL_MERCHANT_ACCOUNT');
    
    // Validate required credentials
    if (!$api_key || !$api_secret || !$merchant_account) {
        return [
            'success' => false, 
            'error' => 'Hubtel API credentials not set', 
            'debug' => [
                'api_key_set' => !empty($api_key),
                'api_secret_set' => !empty($api_secret),
                'merchant_account_set' => !empty($merchant_account),
                'getenv_api_key' => getenv('HUBTEL_API_KEY'),
                'env_api_key' => $_ENV['HUBTEL_API_KEY'] ?? null,
                'server_api_key' => $_SERVER['HUBTEL_API_KEY'] ?? null,
                'defined_api_key' => defined('HUBTEL_API_KEY') ? 'yes' : 'no'
            ]
        ];
    }
    
    // New endpoint URL
    $url = 'https://payproxyapi.hubtel.com/items/initiate';
    
    // Map parameters to the new API format
    $data = [
        'totalAmount' => (float) $params['amount'],
        'description' => $params['description'],
        'callbackUrl' => $params['callbackUrl'],
        'returnUrl' => $params['returnUrl'],
        'merchantAccountNumber' => $merchant_account,
        'cancellationUrl' => $params['cancellationUrl'] ?? $params['returnUrl'], // Use returnUrl as fallback if cancellationUrl not provided
        'clientReference' => $params['clientReference'],
    ];
    
    // Add optional parameters if provided
    if (!empty($params['customerName'])) {
        $data['payeeName'] = $params['customerName'];
    }
    
    if (!empty($params['customerPhone'])) {
        $data['payeeMobileNumber'] = $params['customerPhone'];
    }
    
    if (!empty($params['customerEmail'])) {
        $data['payeeEmail'] = $params['customerEmail'];
    }
    
    // Initialize cURL session
    $ch = curl_init($url);
    $request_body = json_encode($data);
    
    // Set cURL options
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
      //  'Authorization: Basic ' . base64_encode($api_key . ':' . $api_secret)
        'Authorization: Basic UTFad3JtTQo0OTcxNTYyMDM3NTk0YTdmYTBmZWNjYmUxYmRhODM2ZA=='
    ];
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    // Capture headers to aid debugging (e.g., 401 reasons)
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    // Execute cURL request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $raw_headers = $response !== false ? substr($response, 0, (int)$header_size) : '';
    $body = $response !== false ? substr($response, (int)$header_size) : '';
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Log request and response for debugging
    $log_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'request' => $data,
        'response_headers' => $raw_headers,
        'response' => $body ? json_decode($body, true) : null,
        'http_code' => $http_code,
        'curl_error' => $curl_error
    ];
    
    $log_file = __DIR__ . '/../logs/hubtel_api_v2.log';
    file_put_contents($log_file, json_encode($log_data, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);
    
    // Process response
    if ($http_code === 200 && $body) {
        $json = json_decode($body, true);
        
        // Check for checkoutUrl in the response
        if (isset($json['checkoutUrl'])) {
            return [
                'success' => true, 
                'checkoutUrl' => $json['checkoutUrl'],
                'checkoutDirectUrl' => $json['checkoutDirectUrl'] ?? null,
                'checkoutId' => $json['checkoutId'] ?? null,
                'clientReference' => $json['clientReference'] ?? $params['clientReference']
            ];
        }
    }
    
    // Return error details if request failed
    return [
        'success' => false, 
        'error' => 'Unknown error', 
        'debug' => [
            'http_code' => $http_code,
            'response_headers' => $raw_headers,
            'response' => $body ? json_decode($body, true) : null,
            'curl_error' => $curl_error
        ]
    ];
}
