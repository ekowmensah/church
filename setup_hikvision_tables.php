<?php
/**
 * Hikvision Database Setup Script
 * 
 * This script creates the necessary database tables for the Hikvision integration.
 */

require_once 'config/config.php';

// Check if connected to database
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

echo "<h1>Hikvision Database Setup</h1>";

// Create hikvision_devices table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS hikvision_devices (
    id INT(11) NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    ip_address VARCHAR(50) NOT NULL,
    port INT(11) NOT NULL DEFAULT 80,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(100) NOT NULL,
    location VARCHAR(100),
    church_id INT(11),
    firmware_version VARCHAR(50),
    total_users INT(11) DEFAULT 0,
    total_records INT(11) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    last_sync DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($sql) === TRUE) {
    echo "<p>Table 'hikvision_devices' created successfully or already exists.</p>";
} else {
    echo "<p>Error creating table 'hikvision_devices': " . $conn->error . "</p>";
}

// Create hikvision_enrollments table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS hikvision_enrollments (
    id INT(11) NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    device_id INT(11) NOT NULL,
    hikvision_user_id VARCHAR(50) NOT NULL,
    fingerprint_enrolled TINYINT(1) DEFAULT 0,
    face_enrolled TINYINT(1) DEFAULT 0,
    enrollment_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_member_device (member_id, device_id),
    UNIQUE KEY unique_device_user_id (device_id, hikvision_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($sql) === TRUE) {
    echo "<p>Table 'hikvision_enrollments' created successfully or already exists.</p>";
} else {
    echo "<p>Error creating table 'hikvision_enrollments': " . $conn->error . "</p>";
}

// Create hikvision_attendance_logs table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS hikvision_attendance_logs (
    id INT(11) NOT NULL AUTO_INCREMENT,
    device_id INT(11) NOT NULL,
    hikvision_user_id VARCHAR(50) NOT NULL,
    member_id INT(11),
    timestamp DATETIME NOT NULL,
    verification_type VARCHAR(50),
    in_out_mode VARCHAR(20),
    processed TINYINT(1) DEFAULT 0,
    session_id INT(11),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_device_user_timestamp (device_id, hikvision_user_id, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($sql) === TRUE) {
    echo "<p>Table 'hikvision_attendance_logs' created successfully or already exists.</p>";
} else {
    echo "<p>Error creating table 'hikvision_attendance_logs': " . $conn->error . "</p>";
}

// Create hikvision_sync_history table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS hikvision_sync_history (
    id INT(11) NOT NULL AUTO_INCREMENT,
    device_id INT(11) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME,
    status VARCHAR(20) NOT NULL,
    sync_type VARCHAR(20) NOT NULL,
    records_synced INT(11) DEFAULT 0,
    records_processed INT(11) DEFAULT 0,
    initiated_by VARCHAR(100),
    error_message TEXT,
    PRIMARY KEY (id),
    KEY idx_device_start_time (device_id, start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($sql) === TRUE) {
    echo "<p>Table 'hikvision_sync_history' created successfully or already exists.</p>";
} else {
    echo "<p>Error creating table 'hikvision_sync_history': " . $conn->error . "</p>";
}

echo "<p>Database setup completed.</p>";
echo "<p><a href='views/hikvision_devices.php'>Go to Hikvision Devices</a></p>";
?>
