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
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Handle errors
    if ($curl_error) {
        error_log('Arkesel SMS transport failed.');
        return ['status' => 'error', 'message' => 'SMS provider connection failed.'];
    }
    
    if ($http_code !== 200) {
        error_log("Arkesel SMS returned HTTP $http_code.");
        return ['status' => 'error', 'message' => 'SMS provider rejected the request.', 'http_code' => $http_code];
    }
    
    if (empty($response)) {
        $error_msg = 'Empty response from Arkesel API';
        error_log($error_msg);
        return ['status' => 'error', 'message' => $error_msg];
    }
    
    $json = json_decode($response, true);
    if ($json === null) {
        error_log('Arkesel SMS returned an invalid response.');
        return ['status' => 'error', 'message' => 'Invalid response from SMS provider.'];
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
    
    return $json;
}

function send_hubtel_sms($recipients, $message, $sender, $config) {
    if (empty($config['api_key']) || empty($config['api_secret'])) {
        return ['status' => 'error', 'message' => 'Hubtel API credentials not configured'];
    }
    
    // Hubtel uses GET parameters instead of POST JSON
    $base_url = $config['url'];
    $params = [
        'clientid' => $config['api_key'],
        'clientsecret' => $config['api_secret'],
        'from' => $sender,
        'to' => implode(',', $recipients),
        'content' => $message
    ];
    
    $url = $base_url . '?' . http_build_query($params);
    
    $headers = [
        'Accept: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Handle errors
    if ($curl_error) {
        error_log('Hubtel SMS transport failed.');
        return ['status' => 'error', 'message' => 'SMS provider connection failed.'];
    }
    
    if ($http_code !== 200 && $http_code !== 201) {
        error_log("Hubtel SMS returned HTTP $http_code.");
        return ['status' => 'error', 'message' => 'SMS provider rejected the request.', 'http_code' => $http_code];
    }
    
    if (empty($response)) {
        $error_msg = 'Empty response from Hubtel SMS API';
        error_log($error_msg);
        return ['status' => 'error', 'message' => $error_msg];
    }
    
    $json = json_decode($response, true);
    if ($json === null) {
        error_log('Hubtel SMS returned an invalid response.');
        return ['status' => 'error', 'message' => 'Invalid response from SMS provider.'];
    }
    
    // Standardize response format for Hubtel
    // Hubtel returns status: 0 for success, other values for errors
    if (!isset($json['status']) || is_numeric($json['status'])) {
        if (isset($json['status']) && $json['status'] === 0) {
            $json['status'] = 'success';
            $json['message'] = $json['statusDescription'] ?? 'SMS sent successfully';
        } else {
            $json['status'] = 'error';
            $json['message'] = $json['statusDescription'] ?? 'SMS sending failed';
        }
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
    
    return $result;
}
