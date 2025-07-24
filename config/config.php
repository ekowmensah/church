<?php
// Base URL for the application
if (!defined('BASE_URL')) {
    define('BASE_URL', 'https://myfreeman.mensweb.xyz');
}
// Database configuration
// Ensure $conn is global for all includes
if (!isset($GLOBALS['conn'])) {
    $host = 'localhost';
    $db   = 'menswebg_myfreeman';
    $user = 'menswebg_myfreeman';
    $pass = '$Norbert3600$';
    $GLOBALS['conn'] = new mysqli($host, $user, $pass, $db);
    if ($GLOBALS['conn']->connect_error) {
        die('Connection failed: ' . $GLOBALS['conn']->connect_error);
    }
}
$conn = $GLOBALS['conn'];
// SMS Provider configuration
// Always load from sms_settings.json if present
$sms_settings_file = __DIR__.'/sms_settings.json';
if (file_exists($sms_settings_file)) {
    $sms_settings = json_decode(file_get_contents($sms_settings_file), true);
    if (isset($sms_settings['arkesel_api_key'])) {
        define('ARKESEL_API_KEY', $sms_settings['arkesel_api_key']);
    }
    if (isset($sms_settings['sms_sender'])) {
        define('SMS_SENDER', $sms_settings['sms_sender']);
    }
} else {
    define('ARKESEL_API_KEY', getenv('ARKESEL_API_KEY') ?: '');
    define('SMS_SENDER', getenv('SMS_SENDER') ?: 'FMC-KM');
}
define('PAYSTACK_SECRET_KEY', 'sk_test_5db9d47a6fa119ea1ebdbf34965c2452c03f2c9f');
?>
