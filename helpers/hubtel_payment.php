<?php
// Load .env if not already loaded
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv')) {
        // Use createMutable so getenv() works, and call overload() for full compatibility
        $dotenv = Dotenv\Dotenv::createMutable(dirname(__DIR__, 1));
        $dotenv->safeLoad();
        if (method_exists($dotenv, 'overload')) {
            $dotenv->overload();
        }
    }
}
// Fallback: If getenv() still fails, manually parse .env and define constants
function define_env_constant($key) {
    if (getenv($key)) return;
    $envPath = __DIR__ . '/../.env';
    if (!file_exists($envPath)) return;
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($envKey, $envValue) = explode('=', $line, 2);
            $envKey = trim($envKey);
            $envValue = trim($envValue);
            if ($envKey === $key && !defined($key)) {
                define($key, $envValue);
                break;
            }
        }
    }
}
define_env_constant('HUBTEL_API_KEY');
define_env_constant('HUBTEL_API_SECRET');
define_env_constant('HUBTEL_MERCHANT_ACCOUNT');

// helpers/hubtel_payment.php
// Hubtel payment integration utility
// Usage: include this file and call create_hubtel_checkout($params)

function create_hubtel_checkout($params) {
    // Required params: amount, description, callbackUrl, returnUrl, customerName, customerPhone, clientReference
    $readEnv = function ($key, $constFallback = null) {
        $val = getenv($key);
        if ($val === false || $val === null || $val === '') {
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') $val = $_ENV[$key];
            elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') $val = $_SERVER[$key];
            elseif ($constFallback && defined($constFallback)) $val = constant($constFallback);
        }
        return ($val === false || $val === '') ? null : $val;
    };

    $api_key = $readEnv('HUBTEL_API_KEY', 'HUBTEL_API_KEY');
    $api_secret = $readEnv('HUBTEL_API_SECRET', 'HUBTEL_API_SECRET');
    $merchant_account = $readEnv('HUBTEL_MERCHANT_ACCOUNT', 'HUBTEL_MERCHANT_ACCOUNT');
    if (!$api_key || !$api_secret || !$merchant_account) {
        $debug = [
            'getenv_api_key' => getenv('HUBTEL_API_KEY'),
            'env_api_key' => $_ENV['HUBTEL_API_KEY'] ?? null,
            'server_api_key' => $_SERVER['HUBTEL_API_KEY'] ?? null,
            'getenv_api_secret' => getenv('HUBTEL_API_SECRET'),
            'getenv_merchant_account' => getenv('HUBTEL_MERCHANT_ACCOUNT'),
            'defined_api_key' => defined('HUBTEL_API_KEY') ? HUBTEL_API_KEY : null,
            'defined_api_secret' => defined('HUBTEL_API_SECRET') ? HUBTEL_API_SECRET : null,
            'defined_merchant_account' => defined('HUBTEL_MERCHANT_ACCOUNT') ? HUBTEL_MERCHANT_ACCOUNT : null,
            'dot_env_loaded' => class_exists('Dotenv\\Dotenv') ? 'yes' : 'no',
            'file_exists_vendor_autoload' => file_exists(__DIR__ . '/../vendor/autoload.php') ? 'yes' : 'no',
            'env_file_path' => realpath(__DIR__ . '/../.env'),
        ];
        return ['success' => false, 'error' => 'Hubtel API credentials not set', 'debug' => $debug];
    }

    $url = 'https://payproxyapi.hubtel.com/items/initiate';
    $data = [
        'totalAmount' => $params['amount'],
        'description' => $params['description'],
        'callbackUrl' => $params['callbackUrl'],
        'returnUrl' => $params['returnUrl'],
        'merchantAccountNumber' => $merchant_account,
        'clientReference' => $params['clientReference'],
        'cancellationUrl' => $params['returnUrl'], // Use returnUrl for cancellation for now
    ];
    // Optionally add customer info to description
    if (!empty($params['customerName'])) {
        $data['description'] .= ' - ' . $params['customerName'];
    }
    if (!empty($params['customerPhone'])) {
        $data['description'] .= ' (' . $params['customerPhone'] . ')';
    }
    $ch = curl_init($url);
    $request_body = json_encode($data);
    $headers = [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($api_key . ':' . $api_secret)
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    if ($curl_error) {
        return ['success' => false, 'error' => $curl_error, 'debug' => [
            'request_body' => $request_body,
            'headers' => $headers,
            'http_code' => $http_code,
            'curl_error' => $curl_error,
            'response' => $response
        ]];
    }
    $json = $response ? json_decode($response, true) : null;
    if ($http_code === 200 && $json && isset($json['data']['checkoutUrl'])) {
        return ['success' => true, 'checkoutUrl' => $json['data']['checkoutUrl']];
    } else {
        return ['success' => false, 'error' => $json['message'] ?? 'Unknown error', 'debug' => [
            'request_body' => $request_body,
            'headers' => $headers,
            'http_code' => $http_code,
            'response' => $response
        ]];
    }
}
