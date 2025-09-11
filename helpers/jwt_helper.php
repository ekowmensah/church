<?php
/**
 * JWT Helper Functions for Mobile API
 */

/**
 * Generate JWT token
 * @param array $payload
 * @return string
 */
function generate_jwt($payload) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode($payload);
    
    $headerEncoded = base64url_encode($header);
    $payloadEncoded = base64url_encode($payload);
    
    $signature = hash_hmac('sha256', $headerEncoded . "." . $payloadEncoded, get_jwt_secret(), true);
    $signatureEncoded = base64url_encode($signature);
    
    return $headerEncoded . "." . $payloadEncoded . "." . $signatureEncoded;
}

/**
 * Verify JWT token
 * @param string $token
 * @return array|false
 */
function verify_jwt($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    
    list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
    
    $signature = base64url_decode($signatureEncoded);
    $expectedSignature = hash_hmac('sha256', $headerEncoded . "." . $payloadEncoded, get_jwt_secret(), true);
    
    if (!hash_equals($signature, $expectedSignature)) {
        return false;
    }
    
    $payload = json_decode(base64url_decode($payloadEncoded), true);
    
    // Check expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return false;
    }
    
    return $payload;
}

/**
 * Get JWT secret from environment or config
 * @return string
 */
function get_jwt_secret() {
    // Try to get from environment first
    $secret = getenv('JWT_SECRET');
    if ($secret) {
        return $secret;
    }
    
    // Fallback to a default secret (should be changed in production)
    return 'your-church-jwt-secret-key-change-this-in-production';
}

/**
 * Base64 URL encode
 * @param string $data
 * @return string
 */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64 URL decode
 * @param string $data
 * @return string
 */
function base64url_decode($data) {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

/**
 * Middleware to authenticate mobile API requests
 * @return array|false Member data if authenticated, false otherwise
 */
function authenticate_mobile_request() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return false;
    }
    
    $token = $matches[1];
    $payload = verify_jwt($token);
    
    if (!$payload || !isset($payload['member_id'])) {
        return false;
    }
    
    return $payload;
}
?>
