-- Migration: Create hikvision_devices table
-- Date: 2025-08-17
-- Purpose: Add missing HikVision devices table for DS-K1T320MFWX integration

CREATE TABLE `hikvision_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `church_id` int(11) NOT NULL,
  `device_name` varchar(100) NOT NULL,
  `device_model` varchar(50) DEFAULT 'DS-K1T320MFWX',
  `ip_address` varchar(15) NOT NULL,
  `port` int(11) DEFAULT 80,
  `username` varchar(50) NOT NULL,
  `password` varchar(100) NOT NULL,
  `device_serial` varchar(50) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_sync` datetime DEFAULT NULL,
  `sync_status` enum('connected','disconnected','error') DEFAULT 'disconnected',
  `max_users` int(11) DEFAULT 3000,
  `current_users` int(11) DEFAULT 0,
  `firmware_version` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_device_ip` (`ip_address`, `port`),
  KEY `idx_church_id` (`church_id`),
  KEY `idx_active_devices` (`is_active`, `sync_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='HikVision face recognition devices for attendance tracking';

-- Update attendance_records table to support HikVision
ALTER TABLE `attendance_records` 
MODIFY COLUMN `sync_source` enum('manual','zkteco','hikvision','hybrid') DEFAULT 'manual';

-- Add HikVision raw logs table
CREATE TABLE `hikvision_raw_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `user_id` varchar(50) NOT NULL COMMENT 'HikVision device user ID',
  `event_time` datetime NOT NULL,
  `event_type` varchar(20) DEFAULT 'access',
  `verification_mode` varchar(20) DEFAULT 'face',
  `door_id` int(11) DEFAULT 1,
  `raw_data` text DEFAULT NULL COMMENT 'Complete JSON response from device',
  `processed` tinyint(1) DEFAULT 0,
  `processed_at` datetime DEFAULT NULL,
  `attendance_record_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_device_time` (`device_id`, `event_time`),
  KEY `idx_processed` (`processed`),
  KEY `idx_user_time` (`user_id`, `event_time`),
  FOREIGN KEY (`device_id`) REFERENCES `hikvision_devices` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`attendance_record_id`) REFERENCES `attendance_records` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Raw access logs from HikVision devices';

-- Add hikvision_raw_log_id to attendance_records if not exists
ALTER TABLE `attendance_records` 
ADD COLUMN `hikvision_raw_log_id` int(11) DEFAULT NULL AFTER `zkteco_raw_log_id`,
ADD KEY `idx_hikvision_raw_log` (`hikvision_raw_log_id`);
