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
    
    if (!$api_key || !$api_secret) {
        return [
            'success' => false, 
            'error' => 'Hubtel API credentials not set',
            'debug' => [
                'api_key_set' => !empty($api_key),
                'api_secret_set' => !empty($api_secret)
            ]
        ];
    }

    // Build URL with proper format: /transactions/{id}/status?clientReference={ref}
    $url = "https://api-txnstatus.hubtel.com/transactions/{$transaction_id}/status";
    if ($client_reference) {
        $url .= "?clientReference=" . urlencode($client_reference);
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($api_key . ':' . $api_secret),
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Church Management System/1.0');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    
    if ($curl_error) {
        return [
            'success' => false,
            'error' => 'Network error: ' . $curl_error,
            'debug' => [
                'url' => $url,
                'http_code' => $http_code,
                'curl_error' => $curl_error
            ]
        ];
    }
    
    $data = $response ? json_decode($response, true) : null;
    
    if ($http_code === 200 && $data) {
        return [
            'success' => true,
            'data' => $data,
            'http_code' => $http_code,
            'status' => $data['status'] ?? 'unknown',
            'amount' => $data['amount'] ?? null,
            'reference' => $data['clientReference'] ?? null
        ];
    } else {
        return [
            'success' => false,
            'error' => $data['message'] ?? 'HTTP ' . $http_code . ' error from Hubtel API',
            'debug' => [
                'url' => $url,
                'http_code' => $http_code,
                'response' => $response,
                'data' => $data
            ]
        ];
    }
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
        // Check if we have stored transaction ID in the intent
        $transaction_id = $intent['hubtel_transaction_id'] ?? null;
        
        // If still no transaction ID, we can't check status via API
        if (!$transaction_id) {
            return [
                'success' => false,
                'error' => 'No Hubtel transaction ID available for status check',
                'note' => 'Transaction ID is required for Hubtel status API. Consider storing it during payment initiation.',
                'client_reference' => $client_reference
            ];
        }
    }
    
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
                'transaction_id' => $transaction_id
            ];
        } else {
            return [
                'success' => true,
                'status_updated' => false,
                'current_status' => $local_status,
                'hubtel_data' => $status_result['data'],
                'transaction_id' => $transaction_id
            ];
        }
    } else {
        return [
            'success' => false,
            'error' => $status_result['error'],
            'debug' => $status_result['debug'] ?? null,
            'transaction_id' => $transaction_id
        ];
    }
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
