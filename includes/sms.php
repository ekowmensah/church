<?php
require_once __DIR__.'/../config/config.php';

function process_template($message, $data = []) {
    if (empty($data)) return $message;
    
    // Replace {key} with corresponding value from $data
    foreach ($data as $key => $value) {
        $message = str_replace('{' . $key . '}', $value, $message);
    }
    
    // Remove any remaining {tags} to prevent them from appearing in the final message
    return preg_replace('/\{[^}]+\}/', '', $message);
}

function normalize_ghana_phone($phone) {
    // Remove non-digit characters
    $phone = preg_replace('/\D+/', '', $phone);
    // If starts with 0 and is 10 digits, replace with 233
    if (preg_match('/^0\d{9}$/', $phone)) {
        return '233' . substr($phone, 1);
    }
    // If already starts with 233 and is 12 digits, return as is
    if (preg_match('/^233\d{9}$/', $phone)) {
        return $phone;
    }
    // If starts with +233, remove the plus
    if (preg_match('/^\+233\d{9}$/', $phone)) {
        return '233' . substr($phone, 4);
    }
    // Otherwise, return as is (may fail)
    return $phone;
}

function send_sms($recipients, $message, $sender = null, $template_data = [], $provider = null) {
    // Process template variables if any
    $processed_message = !empty($template_data) ? process_template($message, $template_data) : $message;
    
    // Load SMS settings
    $sms_config = get_sms_config();
    if (!$sms_config) {
        error_log('SMS Error: SMS configuration not found');
        return ['status' => 'error', 'message' => 'SMS configuration not found'];
    }
    
    // Determine provider
    $provider = $provider ?: $sms_config['default_provider'];
    if (!isset($sms_config[$provider])) {
        error_log('SMS Error: Provider not configured: ' . $provider);
        return ['status' => 'error', 'message' => 'SMS provider not configured: ' . $provider];
    }
    
    $config = $sms_config[$provider];
    $sender = $sender ?: $config['sender'];
    
    // Normalize all phone numbers to Ghana format
    if (is_array($recipients)) {
        $recipients = array_map('normalize_ghana_phone', $recipients);
    } else {
        $recipients = [normalize_ghana_phone($recipients)];
    }
    
    // Build request based on provider
    if ($provider === 'hubtel') {
        return send_hubtel_sms($recipients, $processed_message, $sender, $config);
    } else {
        return send_arkesel_sms($recipients, $processed_message, $sender, $config);
    }
}

function get_sms_config() {
    $config_file = __DIR__.'/../config/sms_settings.json';
    if (!file_exists($config_file)) {
        return null;
    }
    return json_decode(file_get_contents($config_file), true);
}

function send_arkesel_sms($recipients, $message, $sender, $config) {
    $url = $config['url'];
    $payload = [
        'sender' => $sender,
        'message' => $message,
        'recipients' => $recipients
    ];
    $headers = [
        'api-key: ' . $config['api_key'],
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_VERBOSE => true
    ]);
    
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $response_headers = $header_size ? substr($response, 0, $header_size) : '';
    $body = $header_size ? substr($response, $header_size) : $response;
    
    // Get verbose output
    rewind($verbose);
    $verbose_log = stream_get_contents($verbose);
    fclose($verbose);
    
    // Log everything
    $debug = [
        'time' => date('Y-m-d H:i:s'),
        'url' => $url,
        'payload' => $payload,
        'request_headers' => $headers,
        'http_status' => $http_code,
        'response_headers' => $response_headers,
        'response_body' => $body,
        'curl_error' => $curl_error,
        'curl_info' => [
            'total_time' => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
            'connect_time' => curl_getinfo($ch, CURLINFO_CONNECT_TIME),
            'namelookup_time' => curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME),
            'pretransfer_time' => curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME),
            'starttransfer_time' => curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME),
            'redirect_time' => curl_getinfo($ch, CURLINFO_REDIRECT_TIME),
            'redirect_count' => curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
            'size_upload' => curl_getinfo($ch, CURLINFO_SIZE_UPLOAD),
            'size_download' => curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD),
            'speed_download' => curl_getinfo($ch, CURLINFO_SPEED_DOWNLOAD),
            'speed_upload' => curl_getinfo($ch, CURLINFO_SPEED_UPLOAD),
            'download_content_length' => curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD),
            'upload_content_length' => curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_UPLOAD),
            'content_type' => curl_getinfo($ch, CURLINFO_CONTENT_TYPE)
        ],
        'verbose_log' => $verbose_log
    ];
    
    curl_close($ch);
    
    // Ensure debug directory exists
    $debug_dir = __DIR__.'/../logs';
    if (!is_dir($debug_dir)) {
        @mkdir($debug_dir, 0755, true);
    }
    
    // Log to file with better error handling
    $log_file = $debug_dir.'/sms_debug_'.date('Y-m-d').'.log';
    try {
        $log_entry = json_encode($debug, JSON_PRETTY_PRINT)."\n";
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    } catch (Exception $e) {
        error_log("Failed to write SMS debug log: ".$e->getMessage());
    }
    
    // Handle errors
    if ($curl_error) {
        error_log("cURL Error: $curl_error");
        return ['status' => 'error', 'message' => 'cURL Error: ' . $curl_error, 'debug' => $debug];
    }
    
    if ($http_code !== 200) {
        $error_msg = "HTTP Error: $http_code";
        error_log($error_msg);
        return ['status' => 'error', 'message' => $error_msg, 'http_code' => $http_code, 'debug' => $debug];
    }
    
    if (empty($body)) {
        $error_msg = 'Empty response from Arkesel API';
        error_log($error_msg);
        return ['status' => 'error', 'message' => $error_msg, 'debug' => $debug];
    }
    
    $json = json_decode($body, true);
    if ($json === null) {
        $error_msg = 'Invalid JSON response from Arkesel API: ' . $body;
        error_log($error_msg);
        return ['status' => 'error', 'message' => 'Invalid JSON response from API', 'raw_response' => $body, 'debug' => $debug];
    }
    
    // Standardize response format for Arkesel
    if (!isset($json['status'])) {
        if (isset($json['code']) && $json['code'] === '2000') {
            $json['status'] = 'success';
        } else if (isset($json['statusCode']) && $json['statusCode'] === '200') {
            $json['status'] = 'success';
        } else {
            $json['status'] = 'error';
            if (!isset($json['message']) && isset($json['data']['message'])) {
                $json['message'] = $json['data']['message'];
            }
        }
    }
    
    // Add debug info if in development
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        $json['_debug'] = $debug;
    }
    
    return $json;
}

function send_hubtel_sms($recipients, $message, $sender, $config) {
    if (empty($config['api_key']) || empty($config['api_secret'])) {
        return ['status' => 'error', 'message' => 'Hubtel API credentials not configured'];
    }
    
    $url = $config['url'];
    $payload = [
        'From' => $sender,
        'To' => implode(',', $recipients),
        'Content' => $message,
        'Type' => 0,
        'DlrUrl' => '',
        'ClientReference' => 'SMS_' . time()
    ];
    
    $auth = base64_encode($config['api_key'] . ':' . $config['api_secret']);
    $headers = [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_VERBOSE => true
    ]);
    
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $response_headers = $header_size ? substr($response, 0, $header_size) : '';
    $body = $header_size ? substr($response, $header_size) : $response;
    
    // Get verbose output
    rewind($verbose);
    $verbose_log = stream_get_contents($verbose);
    fclose($verbose);
    
    // Log everything
    $debug = [
        'time' => date('Y-m-d H:i:s'),
        'provider' => 'hubtel',
        'url' => $url,
        'payload' => $payload,
        'request_headers' => $headers,
        'http_status' => $http_code,
        'response_headers' => $response_headers,
        'response_body' => $body,
        'curl_error' => $curl_error,
        'verbose_log' => $verbose_log
    ];
    
    curl_close($ch);
    
    // Log debug info
    $debug_dir = __DIR__.'/../logs';
    if (!is_dir($debug_dir)) {
        @mkdir($debug_dir, 0755, true);
    }
    
    $log_file = $debug_dir.'/hubtel_sms_debug.log';
    try {
        $log_entry = json_encode($debug, JSON_PRETTY_PRINT)."\n";
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    } catch (Exception $e) {
        error_log("Failed to write Hubtel SMS debug log: ".$e->getMessage());
    }
    
    // Handle errors
    if ($curl_error) {
        error_log("Hubtel SMS cURL Error: $curl_error");
        return ['status' => 'error', 'message' => 'cURL Error: ' . $curl_error, 'debug' => $debug];
    }
    
    if ($http_code !== 200 && $http_code !== 201) {
        $error_msg = "Hubtel SMS HTTP Error: $http_code";
        error_log($error_msg);
        return ['status' => 'error', 'message' => $error_msg, 'http_code' => $http_code, 'debug' => $debug];
    }
    
    if (empty($body)) {
        $error_msg = 'Empty response from Hubtel SMS API';
        error_log($error_msg);
        return ['status' => 'error', 'message' => $error_msg, 'debug' => $debug];
    }
    
    $json = json_decode($body, true);
    if ($json === null) {
        $error_msg = 'Invalid JSON response from Hubtel SMS API: ' . $body;
        error_log($error_msg);
        return ['status' => 'error', 'message' => 'Invalid JSON response from API', 'raw_response' => $body, 'debug' => $debug];
    }
    
    // Standardize response format for Hubtel
    if (!isset($json['status'])) {
        if (isset($json['Status']) && $json['Status'] === 0) {
            $json['status'] = 'success';
        } else {
            $json['status'] = 'error';
            if (!isset($json['message']) && isset($json['Message'])) {
                $json['message'] = $json['Message'];
            }
        }
    }
    
    // Add debug info if in development
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        $json['_debug'] = $debug;
    }
    
    return $json;
}

function log_sms($phone, $message, $payment_id = null, $type = 'general', $sender = null, $template_data = [], $provider = null) {
    // Process template variables if any
    $processed_message = !empty($template_data) ? process_template($message, $template_data) : $message;
    
    $result = send_sms($phone, $processed_message, $sender, $template_data, $provider);
    global $conn;
    
    // Get actual provider used
    $sms_config = get_sms_config();
    $actual_provider = $provider ?: ($sms_config ? $sms_config['default_provider'] : 'unknown');
    $status = isset($result['status']) && $result['status'] === 'success' ? 'success' : 'fail';
    $response = json_encode($result, JSON_PRETTY_PRINT);
    
    // Log to database with provider and response
    $stmt = $conn->prepare('INSERT INTO sms_logs (member_id, phone, message, template_name, type, status, provider, sent_at, response) VALUES (NULL, ?, ?, ?, ?, ?, ?, NOW(), ?)');
    $template_name = $type === 'template' ? $type : null;
    $stmt->bind_param('sssssss', $phone, $message, $template_name, $type, $status, $actual_provider, $response);
    $stmt->execute();
    
    // Log to file
    $log = [
        'time' => date('Y-m-d H:i:s'),
        'recipients' => $phone,
        'message' => $message,
        'processed_message' => $processed_message,
        'template_data' => $template_data,
        'sender' => $sender,
        'provider' => $actual_provider,
        'status' => $status,
        'result' => $result
    ];
    file_put_contents(__DIR__.'/../sms_debug.log', json_encode($log, JSON_PRETTY_PRINT)."\n", FILE_APPEND);
    
    return $result;
}
