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

// Database credentials
// Try to get from environment variables first, then fall back to constants
$db_host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'localhost');
$db_user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root');
$db_pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');
$db_name = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'myfreemangit');

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection failed. Please check your configuration.");
}

// Set charset to utf8mb4
$conn->set_charset("utf8mb4");

// Optional: Set timezone for database operations
// Use UTC as default since it's always available in MySQL
$timezone = getenv('TIMEZONE') ?: (defined('TIMEZONE') ? TIMEZONE : 'UTC');

// Only set timezone if not using default
if ($timezone !== 'UTC') {
    try {
        $conn->query("SET time_zone = '$timezone'");
    } catch (Exception $e) {
        error_log("Failed to set timezone: " . $e->getMessage());
        // Fall back to UTC
        $conn->query("SET time_zone = 'UTC'");
    }
}

// Return the connection object
return $conn;
