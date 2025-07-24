-- Migration: Create ZKTeco integration tables
-- This creates the necessary tables for ZKTeco biometric device integration

-- Table to store ZKTeco device information
CREATE TABLE zkteco_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_name VARCHAR(100) NOT NULL,
    ip_address VARCHAR(15) NOT NULL,
    port INT DEFAULT 4370,
    location VARCHAR(255),
    church_id INT,
    is_active BOOLEAN DEFAULT TRUE,
    last_sync TIMESTAMP NULL,
    device_model VARCHAR(50) DEFAULT 'MB460',
    firmware_version VARCHAR(50) NULL,
    total_users INT DEFAULT 0,
    total_records INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE SET NULL,
    INDEX idx_device_ip (ip_address),
    INDEX idx_device_church (church_id),
    INDEX idx_device_active (is_active)
);

-- Table to store member biometric data mapping
CREATE TABLE member_biometric_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    device_id INT NOT NULL,
    zk_user_id VARCHAR(50) NOT NULL, -- ZKTeco device user ID
    fingerprint_enrolled BOOLEAN DEFAULT FALSE,
    face_enrolled BOOLEAN DEFAULT FALSE,
    card_number VARCHAR(50) NULL,
    enrollment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT NULL,
    
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (device_id) REFERENCES zkteco_devices(id) ON DELETE CASCADE,
    UNIQUE KEY unique_member_device (member_id, device_id),
    UNIQUE KEY unique_zk_user_device (zk_user_id, device_id),
    INDEX idx_member_biometric (member_id),
    INDEX idx_zk_user_id (zk_user_id),
    INDEX idx_device_biometric (device_id)
);

-- Table to store raw attendance logs from ZKTeco devices
CREATE TABLE zkteco_raw_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    zk_user_id VARCHAR(50) NOT NULL,
    timestamp DATETIME NOT NULL,
    verification_type ENUM('fingerprint', 'face', 'card', 'password', 'unknown') DEFAULT 'unknown',
    in_out_mode ENUM('check_in', 'check_out', 'break_out', 'break_in', 'overtime_in', 'overtime_out', 'unknown') DEFAULT 'unknown',
    raw_data TEXT NULL, -- Store raw hex data for debugging
    processed BOOLEAN DEFAULT FALSE,
    processed_at TIMESTAMP NULL,
    session_id INT NULL, -- Link to attendance_sessions if processed
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (device_id) REFERENCES zkteco_devices(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE SET NULL,
    INDEX idx_device_timestamp (device_id, timestamp),
    INDEX idx_zk_user_timestamp (zk_user_id, timestamp),
    INDEX idx_processed (processed),
    INDEX idx_timestamp (timestamp),
    UNIQUE KEY unique_device_user_timestamp (device_id, zk_user_id, timestamp)
);

-- Table to store sync history and status
CREATE TABLE zkteco_sync_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    sync_type ENUM('manual', 'automatic', 'scheduled') DEFAULT 'manual',
    sync_status ENUM('success', 'partial', 'failed') DEFAULT 'failed',
    records_synced INT DEFAULT 0,
    records_processed INT DEFAULT 0,
    sync_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sync_end TIMESTAMP NULL,
    error_message TEXT NULL,
    sync_details JSON NULL, -- Store additional sync information
    initiated_by INT NULL, -- User who initiated manual sync
    
    FOREIGN KEY (device_id) REFERENCES zkteco_devices(id) ON DELETE CASCADE,
    FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_device_sync (device_id, sync_start),
    INDEX idx_sync_status (sync_status),
    INDEX idx_sync_type (sync_type)
);

-- Table to store attendance session mapping rules
CREATE TABLE zkteco_session_mapping_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    session_pattern VARCHAR(255) NOT NULL, -- Pattern to match session titles
    time_window_before INT DEFAULT 120, -- Minutes before session start
    time_window_after INT DEFAULT 120, -- Minutes after session start
    auto_create_session BOOLEAN DEFAULT FALSE,
    default_session_title VARCHAR(255) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (device_id) REFERENCES zkteco_devices(id) ON DELETE CASCADE,
    INDEX idx_device_mapping (device_id),
    INDEX idx_mapping_active (is_active)
);

-- Add ZKTeco sync information to existing attendance_records table
-- First add columns without foreign key constraint
ALTER TABLE attendance_records 
ADD COLUMN zkteco_raw_log_id INT NULL,
ADD COLUMN sync_source ENUM('manual', 'zkteco', 'hybrid') DEFAULT 'manual',
ADD COLUMN verification_type VARCHAR(20) NULL,
ADD COLUMN device_timestamp DATETIME NULL,
ADD INDEX idx_sync_source (sync_source),
ADD INDEX idx_zkteco_raw_log (zkteco_raw_log_id);

-- Add foreign key constraint after all tables are created
ALTER TABLE attendance_records 
ADD CONSTRAINT fk_attendance_zkteco_raw_log 
FOREIGN KEY (zkteco_raw_log_id) REFERENCES zkteco_raw_logs(id) ON DELETE SET NULL;

-- Create view for easy attendance data with ZKTeco information
CREATE VIEW attendance_with_zkteco AS
SELECT 
    ar.id,
    ar.session_id,
    ar.member_id,
    ar.status,
    ar.marked_by,
    ar.created_at,
    ar.sync_source,
    ar.verification_type,
    ar.device_timestamp,
    zrl.device_id,
    zd.device_name,
    zd.location as device_location,
    m.first_name,
    m.last_name,
    m.crn,
    ats.title as session_title,
    ats.service_date
FROM attendance_records ar
LEFT JOIN zkteco_raw_logs zrl ON ar.zkteco_raw_log_id = zrl.id
LEFT JOIN zkteco_devices zd ON zrl.device_id = zd.id
LEFT JOIN members m ON ar.member_id = m.id
LEFT JOIN attendance_sessions ats ON ar.session_id = ats.id;

-- Insert default device configuration (placeholder - update with actual device details)
INSERT INTO zkteco_devices (device_name, ip_address, port, location, church_id, is_active) VALUES
('Main Entrance Scanner', '192.168.1.100', 4370, 'Main Church Entrance', 1, FALSE); -- Set to FALSE until actual device is configured

-- Create default session mapping rule
INSERT INTO zkteco_session_mapping_rules (device_id, session_pattern, time_window_before, time_window_after, auto_create_session, default_session_title) VALUES
(1, '%Sunday%Service%', 120, 60, TRUE, 'Sunday Service - Auto Created');

-- Add comments for documentation
ALTER TABLE zkteco_devices COMMENT = 'Stores ZKTeco biometric device configuration and status information';
ALTER TABLE member_biometric_data COMMENT = 'Maps church members to their biometric data on ZKTeco devices';
ALTER TABLE zkteco_raw_logs COMMENT = 'Stores raw attendance logs retrieved from ZKTeco devices before processing';
ALTER TABLE zkteco_sync_history COMMENT = 'Tracks synchronization history and status for ZKTeco devices';
ALTER TABLE zkteco_session_mapping_rules COMMENT = 'Rules for automatically mapping ZKTeco attendance data to attendance sessions';
