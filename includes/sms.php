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

function sms_log_columns($connection) {
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }

    $columns = [];
    try {
        $result = $connection->query('SHOW COLUMNS FROM sms_logs');
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }
    } catch (Throwable $error) {
        error_log('Unable to inspect the SMS audit schema: ' . $error->getMessage());
    }
    return $columns;
}

function sms_provider_message_id($result) {
    $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
    $first = isset($data[0]) && is_array($data[0]) ? $data[0] : [];
    $candidates = [
        $result['message_id'] ?? null,
        $result['messageId'] ?? null,
        $data['id'] ?? null,
        $first['id'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_scalar($candidate) && trim((string) $candidate) !== '') {
            return substr(trim((string) $candidate), 0, 100);
        }
    }
    return null;
}

function sms_insert_audit_row($connection, array $values) {
    $available = sms_log_columns($connection);
    $columns = [];
    $placeholders = [];
    $types = '';
    $parameters = [];

    foreach ($values as $column => $definition) {
        if (!isset($available[$column])) {
            continue;
        }
        $columns[] = '`' . $column . '`';
        $placeholders[] = '?';
        $types .= $definition[0];
        $parameters[] = $definition[1];
    }
    if (!$columns) {
        throw new RuntimeException('The sms_logs table has no compatible audit columns.');
    }

    $stmt = $connection->prepare(
        'INSERT INTO sms_logs (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', $placeholders) . ')'
    );
    $bind = [$types];
    foreach ($parameters as $index => $value) {
        $parameters[$index] = $value;
        $bind[] = &$parameters[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $id = (int) $connection->insert_id;
    $stmt->close();
    return $id;
}

function log_sms(
    $phone,
    $message,
    $payment_id = null,
    $type = 'general',
    $sender = null,
    $template_data = [],
    $provider = null,
    $retry_of_sms_log_id = null,
    $attempted_by_user_id = null
) {
    $processed_message = !empty($template_data) ? process_template($message, $template_data) : $message;
    // The message is already rendered, so do not process the template a second time.
    $result = send_sms($phone, $processed_message, $sender, [], $provider);
    if (!is_array($result)) {
        $result = ['status' => 'error', 'message' => 'SMS provider returned an invalid result.'];
    }

    global $conn;
    $sms_config = get_sms_config();
    $actual_provider = $provider ?: ($sms_config['default_provider'] ?? 'unknown');
    $provider_config = is_array($sms_config) ? ($sms_config[$actual_provider] ?? []) : [];
    $actual_sender = $sender ?: ($provider_config['sender'] ?? null);
    $status = strtolower((string) ($result['status'] ?? '')) === 'success' ? 'success' : 'fail';
    $response = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($response === false) {
        $response = '{"status":"error","message":"Provider response could not be encoded."}';
    }

    $paymentId = (int) ($payment_id ?? 0);
    $memberId = (int) ($template_data['member_id'] ?? 0);
    $sundaySchoolId = (int) ($template_data['sundayschool_id'] ?? 0);
    $churchId = (int) ($template_data['church_id'] ?? 0);
    $retryId = (int) ($retry_of_sms_log_id ?? 0);
    $actorId = (int) ($attempted_by_user_id ?? $template_data['sent_by'] ?? ($_SESSION['user_id'] ?? 0));
    $attemptNumber = 1;

    try {
        if ($paymentId > 0 && ($memberId <= 0 || $sundaySchoolId <= 0 || $churchId <= 0)) {
            $context = $conn->prepare(
                'SELECT member_id, sundayschool_id, church_id FROM payments WHERE id = ? LIMIT 1'
            );
            $context->bind_param('i', $paymentId);
            $context->execute();
            $payment = $context->get_result()->fetch_assoc() ?: [];
            $context->close();
            $memberId = $memberId > 0 ? $memberId : (int) ($payment['member_id'] ?? 0);
            $sundaySchoolId = $sundaySchoolId > 0
                ? $sundaySchoolId
                : (int) ($payment['sundayschool_id'] ?? 0);
            $churchId = $churchId > 0 ? $churchId : (int) ($payment['church_id'] ?? 0);
        }
        if ($churchId <= 0 && $memberId > 0) {
            $context = $conn->prepare('SELECT church_id FROM members WHERE id = ? LIMIT 1');
            $context->bind_param('i', $memberId);
            $context->execute();
            $churchId = (int) (($context->get_result()->fetch_assoc()['church_id'] ?? 0));
            $context->close();
        }
        if ($churchId <= 0 && $sundaySchoolId > 0) {
            $context = $conn->prepare('SELECT church_id FROM sunday_school WHERE id = ? LIMIT 1');
            $context->bind_param('i', $sundaySchoolId);
            $context->execute();
            $churchId = (int) (($context->get_result()->fetch_assoc()['church_id'] ?? 0));
            $context->close();
        }
        $auditColumns = sms_log_columns($conn);
        if ($retryId > 0 && isset($auditColumns['attempt_number'])) {
            $context = $conn->prepare('SELECT attempt_number FROM sms_logs WHERE id = ? LIMIT 1');
            $context->bind_param('i', $retryId);
            $context->execute();
            $attemptNumber = max(2, (int) (($context->get_result()->fetch_assoc()['attempt_number'] ?? 1)) + 1);
            $context->close();
        }

        $templateName = $type === 'template' ? $type : null;
        $errorMessage = $status === 'success'
            ? null
            : substr((string) ($result['message'] ?? 'SMS delivery failed.'), 0, 500);
        $logId = sms_insert_audit_row($conn, [
            'member_id' => ['i', $memberId > 0 ? $memberId : null],
            'sundayschool_id' => ['i', $sundaySchoolId > 0 ? $sundaySchoolId : null],
            'church_id' => ['i', $churchId > 0 ? $churchId : null],
            'payment_id' => ['i', $paymentId > 0 ? $paymentId : null],
            'phone' => ['s', $phone],
            'message' => ['s', $processed_message],
            'template_name' => ['s', $templateName],
            'type' => ['s', $type],
            'status' => ['s', $status],
            'provider' => ['s', $actual_provider],
            'sender' => ['s', $actual_sender],
            'provider_message_id' => ['s', sms_provider_message_id($result)],
            'attempt_number' => ['i', $attemptNumber],
            'retry_of_sms_log_id' => ['i', $retryId > 0 ? $retryId : null],
            'attempted_by_user_id' => ['i', $actorId > 0 ? $actorId : null],
            'response' => ['s', $response],
            'error_message' => ['s', $errorMessage],
        ]);
        $result['log_status'] = 'logged';
        $result['sms_log_id'] = $logId;
    } catch (Throwable $error) {
        // Provider delivery has already happened. A logging failure must not
        // make a payment fail or encourage the caller to send the SMS again.
        error_log('SMS was attempted but its audit row could not be saved: ' . $error->getMessage());
        $result['log_status'] = 'error';
        $result['log_error'] = 'SMS delivery was attempted, but audit logging failed.';
    }

    return $result;
}
