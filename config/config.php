<?php
//session_start();
// Load server-local secrets before any service reads getenv(). The unsafe
// factory name refers to putenv() support; the file itself remains server-side
// and must never be committed or exposed to the browser.
$projectRoot = dirname(__DIR__);
$composerAutoload = $projectRoot . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    if (class_exists('Dotenv\\Dotenv') && file_exists($projectRoot . '/.env')) {
        Dotenv\Dotenv::createUnsafeMutable($projectRoot)->safeLoad();
    }
}
require_once __DIR__ . '/timezone.php';
initialize_app_timezone();
// Base URL for the application
if (!defined('BASE_URL')) {
    $configuredBaseUrl = trim((string) (getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? '')));
    define('BASE_URL', rtrim($configuredBaseUrl !== '' ? $configuredBaseUrl : 'http://localhost/myfreemanchurchgit/church', '/'));
}
// Database configuration
// Ensure $conn is global for all includes
if (!isset($GLOBALS['conn'])) {
    $host = (string) (getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'localhost'));
    $port = (int) (getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? 3306));
    $db   = (string) (getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? 'myfreemangit'));
    $user = (string) (getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? 'root'));
    $pass = (string) (getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? ''));
    $GLOBALS['conn'] = new mysqli($host, $user, $pass, $db, $port);
    if ($GLOBALS['conn']->connect_error) {
        die('Connection failed: ' . $GLOBALS['conn']->connect_error);
    }
    $GLOBALS['conn']->set_charset('utf8mb4');
    initialize_app_timezone($GLOBALS['conn']);
}
$conn = $GLOBALS['conn'];
// Includes may provide an existing connection. Ensure its session uses the
// same timezone as PHP as well.
initialize_app_timezone($conn);
// SMS Provider configuration
// Always load from sms_settings.json if present
$sms_settings_file = __DIR__.'/sms_settings.json';
$sms_settings = [];
if (file_exists($sms_settings_file)) {
    $decodedSmsSettings = json_decode((string) file_get_contents($sms_settings_file), true);
    $sms_settings = is_array($decodedSmsSettings) ? $decodedSmsSettings : [];
}
if (!defined('ARKESEL_API_KEY')) {
    define('ARKESEL_API_KEY', (string) ($sms_settings['arkesel_api_key'] ?? (getenv('ARKESEL_API_KEY') ?: '')));
}
if (!defined('SMS_SENDER')) {
    define('SMS_SENDER', (string) ($sms_settings['sms_sender'] ?? (getenv('SMS_SENDER') ?: 'FMC-KM')));
}
if (!defined('PAYSTACK_SECRET_KEY')) {
    define('PAYSTACK_SECRET_KEY', (string) (getenv('PAYSTACK_SECRET_KEY') ?: ($_ENV['PAYSTACK_SECRET_KEY'] ?? $_SERVER['PAYSTACK_SECRET_KEY'] ?? '')));
}
?>
