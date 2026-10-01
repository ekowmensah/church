<?php
/**
 * Database Configuration
 * 
 * This file contains the database connection settings for the application.
 */

// Load environment variables if available
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createMutable(__DIR__ . '/..');
        $dotenv->safeLoad();
    }
}
require_once __DIR__ . '/timezone.php';
initialize_app_timezone();

// Database credentials
// Try to get from environment variables first, then fall back to constants
$db_host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? (defined('DB_HOST') ? DB_HOST : 'localhost'));
$db_port = (int) (getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? (defined('DB_PORT') ? DB_PORT : 3306)));
$db_user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? (defined('DB_USER') ? DB_USER : 'root'));
$db_pass = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? (defined('DB_PASS') ? DB_PASS : ''));
$db_name = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? (defined('DB_NAME') ? DB_NAME : 'myfreemangit'));

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);

// Check connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection failed. Please check your configuration.");
}

// Set charset to utf8mb4
$conn->set_charset("utf8mb4");

initialize_app_timezone($conn);

// Return the connection object
return $conn;
