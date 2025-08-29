<?php
// Load .env if not already loaded
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv')) {
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

/**
 * Check Hubtel transaction status using the transaction status API
 * @param string $transaction_id The transaction ID from Hubtel
 * @param string $client_reference The client reference for the transaction
 * @return array Response with success status and transaction data
 */
function check_hubtel_transaction_status($transaction_id, $client_reference = null) {
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
    
    // Debug credentials loading
    $auth_string = base64_encode($api_key . ':' . $api_secret);
    file_put_contents(__DIR__.'/../logs/hubtel_debug.log', date('c') . " - Auth Debug: " . json_encode([
        'api_key_length' => strlen($api_key ?? ''),
        'api_secret_length' => strlen($api_secret ?? ''),
        'auth_header' => 'Basic ' . substr($auth_string, 0, 20) . '...',
        'merchant_account' => $merchant_account
    ]) . "\n", FILE_APPEND);
    
    if (!$api_key || !$api_secret) {
        return [
            'success' => false, 
            'error' => 'Hubtel API credentials not complete',
            'debug' => [
                'api_key_set' => !empty($api_key),
                'api_secret_set' => !empty($api_secret),
                'merchant_account_set' => !empty($merchant_account),
                'api_key_length' => strlen($api_key ?? ''),
                'api_secret_length' => strlen($api_secret ?? '')
            ]
        ];
    }

    // Use Hubtel's checkout status endpoint instead of transaction status
    // The checkout API uses a different endpoint structure
    $url = "https://api.hubtel.com/v2/checkout/invoice/status";
    
    // Add clientReference as query parameter
    if ($client_reference) {
        $url .= "?clientReference=" . urlencode($client_reference);
    }
    
    // Try different authentication methods since transaction status API may differ from checkout API
    $auth_methods = [
        // Method 1: Basic auth with API key:secret
        'Authorization: Basic ' . base64_encode($api_key . ':' . $api_secret),
        // Method 2: Basic auth with merchant account:API key (some Hubtel APIs use this)
        'Authorization: Basic ' . base64_encode($merchant_account . ':' . $api_key),
        // Method 3: API key as bearer token
        'Authorization: Bearer ' . $api_key
    ];
    
    $last_response = null;
    $last_http_code = null;
    
    foreach ($auth_methods as $auth_header) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            $auth_header,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Church Management System/1.0');
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        
        // Store for debugging
        $last_response = $response;
        $last_http_code = $http_code;
        
        // Log each auth attempt with raw response
        file_put_contents(__DIR__.'/../logs/hubtel_debug.log', date('c') . " - Auth Method: " . substr($auth_header, 0, 30) . "... HTTP: $http_code\n", FILE_APPEND);
        file_put_contents(__DIR__.'/../logs/hubtel_debug.log', date('c') . " - Raw Response: " . $response . "\n", FILE_APPEND);
        
        if (!$curl_error && $http_code === 200) {
            $data = $response ? json_decode($response, true) : null;
            if ($data && isset($data['responseCode']) && $data['responseCode'] === '0000') {
                // Parse the new Hubtel API response structure
                $transactionData = $data['data'] ?? [];
                return [
                    'success' => true,
                    'data' => $data,
                    'http_code' => $http_code,
                    'status' => $transactionData['status'] ?? 'unknown',
                    'amount' => $transactionData['amount'] ?? null,
                    'reference' => $transactionData['clientReference'] ?? null,
                    'transaction_id' => $transactionData['transactionId'] ?? null,
                    'external_transaction_id' => $transactionData['externalTransactionId'] ?? null,
                    'payment_method' => $transactionData['paymentMethod'] ?? null,
                    'charges' => $transactionData['charges'] ?? null,
                    'amount_after_charges' => $transactionData['amountAfterCharges'] ?? null,
                    'date' => $transactionData['date'] ?? null,
                    'endpoint_used' => $url,
                    'auth_method_used' => $auth_header
                ];
            }
        }
    }
    
    // If all auth methods failed
    return [
        'success' => false,
        'error' => 'All authentication methods failed. HTTP ' . $last_http_code . ' error from Hubtel API',
        'debug' => [
            'url' => $url,
            'last_http_code' => $last_http_code,
            'last_response' => $last_response,
            'auth_methods_tried' => count($auth_methods),
            'transaction_id' => $transaction_id,
            'client_reference' => $client_reference
        ]
    ];
}

/**
 * Check transaction status by client reference
 * @param object $conn Database connection
 * @param string $client_reference The client reference from payment intent
 * @param string $transaction_id Optional Hubtel transaction ID
 * @return array Status check result
 */
function check_transaction_by_reference($conn, $client_reference, $transaction_id = null) {
    // First get the payment intent
    $stmt = $conn->prepare("SELECT * FROM payment_intents WHERE client_reference = ?");
    $stmt->bind_param('s', $client_reference);
    $stmt->execute();
    $intent = $stmt->get_result()->fetch_assoc();
    
    if (!$intent) {
        return [
            'success' => false,
            'error' => 'Payment intent not found',
            'client_reference' => $client_reference
        ];
    }
    
    // If no transaction ID provided, try to extract from stored data or use client reference
    if (!$transaction_id) {
        // Check if we have stored transaction ID in the intent (if column exists)
        $transaction_id = isset($intent['hubtel_transaction_id']) ? $intent['hubtel_transaction_id'] : null;
        
        // If still no transaction ID, use client_reference as fallback
        // This allows status checking for older payment intents before the migration
        if (!$transaction_id) {
            $transaction_id = $client_reference;
            
            // Log that we're using fallback method
            error_log("Hubtel Status Check: Using client_reference as transaction_id fallback for {$client_reference}");
        }
    }
    
    // Try the Hubtel API if we have a transaction ID
    if ($transaction_id) {
        $status_result = check_hubtel_transaction_status($transaction_id, $client_reference);
        
        if ($status_result['success']) {
            // Update local status if different
            $hubtel_status = $status_result['status'];
            $local_status = match (strtolower($hubtel_status)) {
                'success', 'completed' => 'Completed',
                'failed', 'cancelled' => 'Failed',
                default => 'Pending',
            };
            
            if ($local_status !== $intent['status']) {
                $update_stmt = $conn->prepare("UPDATE payment_intents SET status = ?, updated_at = NOW() WHERE client_reference = ?");
                $update_stmt->bind_param('ss', $local_status, $client_reference);
                $update_stmt->execute();
                
                return [
                    'success' => true,
                    'status_updated' => true,
                    'old_status' => $intent['status'],
                    'new_status' => $local_status,
                    'hubtel_data' => $status_result['data'],
                    'transaction_id' => $transaction_id,
                    'method' => 'hubtel_api'
                ];
            } else {
                return [
                    'success' => true,
                    'status_updated' => false,
                    'current_status' => $local_status,
                    'hubtel_data' => $status_result['data'],
                    'transaction_id' => $transaction_id,
                    'method' => 'hubtel_api'
                ];
            }
        }
    }
    
    // If API fails or we're using client_reference as transaction_id, 
    // return current status from database (webhook-based system)
    return [
        'success' => true,
        'status_updated' => false,
        'current_status' => $intent['status'],
        'note' => 'Status retrieved from local database. Hubtel updates status via webhook callbacks.',
        'transaction_id' => $transaction_id,
        'method' => 'database_only',
        'api_error' => isset($status_result) ? $status_result['error'] : 'Transaction ID is client reference - API not attempted'
    ];
}

/**
 * Bulk check status for all pending payment intents
 * @param object $conn Database connection
 * @param int $limit Maximum number of records to check
 * @return array Results of bulk status check
 */
function bulk_check_pending_payments($conn, $limit = 50) {
    $stmt = $conn->prepare("SELECT client_reference, created_at FROM payment_intents WHERE status = 'Pending' ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $pending_intents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $results = [
        'total_checked' => 0,
        'updated_count' => 0,
        'failed_count' => 0,
        'details' => []
    ];
    
    foreach ($pending_intents as $intent) {
        $results['total_checked']++;
        $check_result = check_transaction_by_reference($conn, $intent['client_reference']);
        
        if ($check_result['success']) {
            if ($check_result['status_updated'] ?? false) {
                $results['updated_count']++;
            }
        } else {
            $results['failed_count']++;
        }
        
        $results['details'][] = [
            'client_reference' => $intent['client_reference'],
            'created_at' => $intent['created_at'],
            'result' => $check_result
        ];
    }
    
    return $results;
}
