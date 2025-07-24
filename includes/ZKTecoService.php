<?php
/**
 * ZKTeco Integration Service
 * 
 * This service handles all ZKTeco device communication, data synchronization,
 * and attendance processing for the church management system.
 */

require_once __DIR__ . '/zklibrary.php';
require_once __DIR__ . '/../config/config.php';

class ZKTecoService {
    private $conn;
    private $devices = [];
    
    public function __construct() {
        global $conn;
        $this->conn = $conn;
        $this->loadActiveDevices();
    }
    
    /**
     * Load all active ZKTeco devices from database
     */
    private function loadActiveDevices() {
        $query = "SELECT * FROM zkteco_devices WHERE is_active = TRUE ORDER BY id";
        $result = $this->conn->query($query);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $this->devices[$row['id']] = $row;
            }
        }
    }
    
    /**
     * Test connection to a specific device
     */
    public function testDeviceConnection($device_id) {
        if (!isset($this->devices[$device_id])) {
            return ['success' => false, 'message' => 'Device not found'];
        }
        
        $device = $this->devices[$device_id];
        
        try {
            $zk = new ZKLibrary($device['ip_address'], $device['port'], 'TCP');
            
            if ($zk->connect()) {
                $version = $zk->getVersion();
                $userCount = $zk->getSizeUser();
                $recordCount = $zk->getSizeAttendance();
                
                $zk->disconnect();
                
                // Update device info in database
                $this->updateDeviceInfo($device_id, $version, $userCount, $recordCount);
                
                return [
                    'success' => true,
                    'message' => 'Connection successful',
                    'version' => $version,
                    'users' => $userCount,
                    'records' => $recordCount
                ];
            } else {
                return ['success' => false, 'message' => 'Failed to connect to device'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Connection error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update device information in database
     */
    private function updateDeviceInfo($device_id, $version, $userCount, $recordCount) {
        $stmt = $this->conn->prepare("
            UPDATE zkteco_devices 
            SET firmware_version = ?, total_users = ?, total_records = ?, last_sync = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param('siii', $version, $userCount, $recordCount, $device_id);
        $stmt->execute();
    }
    
    /**
     * Synchronize attendance data from all active devices
     */
    public function syncAllDevices($initiated_by = null) {
        $results = [];
        
        foreach ($this->devices as $device_id => $device) {
            $results[$device_id] = $this->syncDeviceAttendance($device_id, 'automatic', $initiated_by);
        }
        
        return $results;
    }
    
    /**
     * Synchronize attendance data from a specific device
     */
    public function syncDeviceAttendance($device_id, $sync_type = 'manual', $initiated_by = null) {
        if (!isset($this->devices[$device_id])) {
            return ['success' => false, 'message' => 'Device not found'];
        }
        
        $device = $this->devices[$device_id];
        
        // Start sync history record
        $sync_history_id = $this->startSyncHistory($device_id, $sync_type, $initiated_by);
        
        try {
            $zk = new ZKLibrary($device['ip_address'], $device['port'], 'TCP');
            
            if (!$zk->connect()) {
                $this->updateSyncHistory($sync_history_id, 'failed', 0, 0, 'Failed to connect to device');
                return ['success' => false, 'message' => 'Failed to connect to device'];
            }
            
            // Disable device temporarily to prevent interference
            $zk->disableDevice();
            
            // Get attendance data
            $attendance_data = $zk->getAttendance();
            
            // Re-enable device
            $zk->enableDevice();
            $zk->disconnect();
            
            if (empty($attendance_data)) {
                $this->updateSyncHistory($sync_history_id, 'success', 0, 0, null);
                return ['success' => true, 'message' => 'No new attendance data found', 'records' => 0];
            }
            
            // Process attendance records
            $processed_count = 0;
            $synced_count = 0;
            
            foreach ($attendance_data as $record) {
                if ($this->processRawAttendanceLog($record, $device_id)) {
                    $synced_count++;
                }
                $processed_count++;
            }
            
            // Update sync history
            $this->updateSyncHistory($sync_history_id, 'success', $synced_count, $processed_count, null);
            
            return [
                'success' => true,
                'message' => "Successfully synced {$synced_count} records",
                'records' => $synced_count,
                'processed' => $processed_count
            ];
            
        } catch (Exception $e) {
            $this->updateSyncHistory($sync_history_id, 'failed', 0, 0, $e->getMessage());
            return ['success' => false, 'message' => 'Sync error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Process raw attendance log from ZKTeco device
     */
    private function processRawAttendanceLog($record, $device_id) {
        // Check if this record already exists
        $stmt = $this->conn->prepare("
            SELECT id FROM zkteco_raw_logs 
            WHERE device_id = ? AND zk_user_id = ? AND timestamp = ?
        ");
        $stmt->bind_param('iss', $device_id, $record['user_id'], $record['timestamp']);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        
        if ($existing) {
            return false; // Record already exists
        }
        
        // Map verification type
        $verification_type = $this->mapVerificationType($record['verify_type']);
        $in_out_mode = $this->mapInOutMode($record['in_out_mode']);
        
        // Insert raw log
        $stmt = $this->conn->prepare("
            INSERT INTO zkteco_raw_logs 
            (device_id, zk_user_id, timestamp, verification_type, in_out_mode, raw_data, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('isssss', 
            $device_id, 
            $record['user_id'], 
            $record['timestamp'], 
            $verification_type, 
            $in_out_mode, 
            $record['raw_data']
        );
        
        return $stmt->execute();
    }
    
    /**
     * Map ZKTeco verification type to readable format
     */
    private function mapVerificationType($verify_type) {
        switch ($verify_type) {
            case 1: return 'fingerprint';
            case 15: return 'face';
            case 5: return 'card';
            case 0: return 'password';
            default: return 'unknown';
        }
    }
    
    /**
     * Map ZKTeco in/out mode to readable format
     */
    private function mapInOutMode($in_out_mode) {
        switch ($in_out_mode) {
            case 0: return 'check_in';
            case 1: return 'check_out';
            case 2: return 'break_out';
            case 3: return 'break_in';
            case 4: return 'overtime_in';
            case 5: return 'overtime_out';
            default: return 'unknown';
        }
    }
    
    /**
     * Start sync history record
     */
    private function startSyncHistory($device_id, $sync_type, $initiated_by) {
        $stmt = $this->conn->prepare("
            INSERT INTO zkteco_sync_history 
            (device_id, sync_type, sync_status, initiated_by, sync_start) 
            VALUES (?, ?, 'failed', ?, NOW())
        ");
        $stmt->bind_param('isi', $device_id, $sync_type, $initiated_by);
        $stmt->execute();
        
        return $this->conn->insert_id;
    }
    
    /**
     * Update sync history record
     */
    private function updateSyncHistory($sync_history_id, $status, $synced, $processed, $error_message) {
        $stmt = $this->conn->prepare("
            UPDATE zkteco_sync_history 
            SET sync_status = ?, records_synced = ?, records_processed = ?, 
                sync_end = NOW(), error_message = ?
            WHERE id = ?
        ");
        $stmt->bind_param('siisi', $status, $synced, $processed, $error_message, $sync_history_id);
        $stmt->execute();
    }
    
    /**
     * Map ZKTeco attendance logs to attendance sessions
     */
    public function mapLogsToSessions($device_id = null, $session_id = null) {
        $device_filter = $device_id ? "AND zrl.device_id = {$device_id}" : "";
        $session_filter = $session_id ? "AND ats.id = {$session_id}" : "";
        
        // Get unmapped logs within session time windows
        $query = "
            SELECT zrl.*, mbd.member_id, ats.id as session_id, ats.title, ats.service_date,
                   zsmr.time_window_before, zsmr.time_window_after
            FROM zkteco_raw_logs zrl
            JOIN member_biometric_data mbd ON zrl.zk_user_id = mbd.zk_user_id AND zrl.device_id = mbd.device_id
            JOIN members m ON mbd.member_id = m.id
            JOIN attendance_sessions ats ON m.church_id = ats.church_id
            JOIN zkteco_session_mapping_rules zsmr ON zrl.device_id = zsmr.device_id
            WHERE zrl.processed = FALSE 
            AND mbd.is_active = TRUE
            AND zsmr.is_active = TRUE
            AND (ats.title LIKE zsmr.session_pattern OR zsmr.session_pattern = '%')
            AND zrl.timestamp >= DATE_SUB(ats.service_date, INTERVAL zsmr.time_window_before MINUTE)
            AND zrl.timestamp <= DATE_ADD(ats.service_date, INTERVAL zsmr.time_window_after MINUTE)
            {$device_filter}
            {$session_filter}
            ORDER BY zrl.timestamp ASC
        ";
        
        $result = $this->conn->query($query);
        $mapped_count = 0;
        
        if ($result) {
            while ($log = $result->fetch_assoc()) {
                if ($this->createAttendanceRecord($log)) {
                    $mapped_count++;
                }
            }
        }
        
        return $mapped_count;
    }
    
    /**
     * Create attendance record from ZKTeco log
     */
    private function createAttendanceRecord($log) {
        // Check if attendance record already exists for this session and member
        $stmt = $this->conn->prepare("
            SELECT id FROM attendance_records 
            WHERE session_id = ? AND member_id = ?
        ");
        $stmt->bind_param('ii', $log['session_id'], $log['member_id']);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        
        if ($existing) {
            // Update existing record with ZKTeco information
            $stmt = $this->conn->prepare("
                UPDATE attendance_records 
                SET status = 'present', sync_source = 'zkteco', verification_type = ?, 
                    device_timestamp = ?, zkteco_raw_log_id = ?
                WHERE id = ?
            ");
            $stmt->bind_param('ssii', 
                $log['verification_type'], 
                $log['timestamp'], 
                $log['id'], 
                $existing['id']
            );
        } else {
            // Create new attendance record
            $stmt = $this->conn->prepare("
                INSERT INTO attendance_records 
                (session_id, member_id, status, marked_by, sync_source, verification_type, 
                 device_timestamp, zkteco_raw_log_id, created_at) 
                VALUES (?, ?, 'present', 1, 'zkteco', ?, ?, ?, NOW())
            ");
            $stmt->bind_param('iissi', 
                $log['session_id'], 
                $log['member_id'], 
                $log['verification_type'], 
                $log['timestamp'], 
                $log['id']
            );
        }
        
        if ($stmt->execute()) {
            // Mark raw log as processed
            $this->markLogAsProcessed($log['id'], $log['session_id']);
            return true;
        }
        
        return false;
    }
    
    /**
     * Mark raw log as processed
     */
    private function markLogAsProcessed($log_id, $session_id) {
        $stmt = $this->conn->prepare("
            UPDATE zkteco_raw_logs 
            SET processed = TRUE, processed_at = NOW(), session_id = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ii', $session_id, $log_id);
        $stmt->execute();
    }
    
    /**
     * Get device statistics
     */
    public function getDeviceStats($device_id) {
        $stats = [];
        
        // Basic device info
        if (isset($this->devices[$device_id])) {
            $stats['device'] = $this->devices[$device_id];
        }
        
        // Raw logs count
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as total, 
                   SUM(CASE WHEN processed = TRUE THEN 1 ELSE 0 END) as processed,
                   MAX(timestamp) as latest_log
            FROM zkteco_raw_logs WHERE device_id = ?
        ");
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $stats['logs'] = $stmt->get_result()->fetch_assoc();
        
        // Enrolled members count
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN fingerprint_enrolled = TRUE THEN 1 ELSE 0 END) as fingerprint,
                   SUM(CASE WHEN face_enrolled = TRUE THEN 1 ELSE 0 END) as face
            FROM member_biometric_data WHERE device_id = ? AND is_active = TRUE
        ");
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $stats['enrolled'] = $stmt->get_result()->fetch_assoc();
        
        // Recent sync history
        $stmt = $this->conn->prepare("
            SELECT * FROM zkteco_sync_history 
            WHERE device_id = ? 
            ORDER BY sync_start DESC 
            LIMIT 5
        ");
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $stats['sync_history'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        return $stats;
    }
    
    /**
     * Generate unique ZK User ID for a member on a specific device
     */
    public function generateUniqueZKUserId($device_id, $member_id = null) {
        if ($member_id) {
            // Get member CRN to use as ZK user ID
            $stmt = $this->conn->prepare("SELECT crn FROM members WHERE id = ?");
            $stmt->bind_param('i', $member_id);
            $stmt->execute();
            $member = $stmt->get_result()->fetch_assoc();
            
            if ($member && is_numeric($member['crn'])) {
                // Check if this CRN is already used on this device
                $stmt = $this->conn->prepare("
                    SELECT id FROM member_biometric_data 
                    WHERE device_id = ? AND zk_user_id = ?
                ");
                $stmt->bind_param('is', $device_id, $member['crn']);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                
                if (!$existing) {
                    return $member['crn'];
                }
            }
        }
        
        // Generate a unique ID by finding the next available ID
        $stmt = $this->conn->prepare("
            SELECT MAX(CAST(zk_user_id AS UNSIGNED)) as max_id 
            FROM member_biometric_data 
            WHERE device_id = ? AND zk_user_id REGEXP '^[0-9]+$'
        ");
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        $next_id = ($result['max_id'] ?? 0) + 1;
        
        // Ensure the generated ID doesn't conflict with existing ones
        do {
            $stmt = $this->conn->prepare("
                SELECT id FROM member_biometric_data 
                WHERE device_id = ? AND zk_user_id = ?
            ");
            $stmt->bind_param('is', $device_id, $next_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            
            if (!$existing) {
                return (string)$next_id;
            }
            $next_id++;
        } while ($next_id < 99999); // Safety limit
        
        return (string)$next_id;
    }
    
    /**
     * Enroll member to device (placeholder - requires physical presence)
     */
    public function enrollMember($member_id, $device_id, $enrollment_type = 'fingerprint') {
        // This is a placeholder function - actual enrollment requires physical presence
        // and interaction with the device's enrollment interface
        
        // Generate ZK user ID based on member CRN or ID
        $stmt = $this->conn->prepare("SELECT crn, first_name, last_name FROM members WHERE id = ?");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        
        if (!$member) {
            return ['success' => false, 'message' => 'Member not found'];
        }
        
        // Use CRN as ZK user ID, or generate one if CRN is not numeric
        $zk_user_id = is_numeric($member['crn']) ? $member['crn'] : $member_id;
        
        // Check if already enrolled
        $stmt = $this->conn->prepare("
            SELECT id FROM member_biometric_data 
            WHERE member_id = ? AND device_id = ?
        ");
        $stmt->bind_param('ii', $member_id, $device_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        
        if ($existing) {
            return ['success' => false, 'message' => 'Member already enrolled on this device'];
        }
        
        // Insert enrollment record (actual biometric enrollment must be done on device)
        $stmt = $this->conn->prepare("
            INSERT INTO member_biometric_data 
            (member_id, device_id, zk_user_id, fingerprint_enrolled, face_enrolled, enrollment_date) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $fingerprint_enrolled = ($enrollment_type === 'fingerprint') ? 1 : 0;
        $face_enrolled = ($enrollment_type === 'face') ? 1 : 0;
        
        $stmt->bind_param('iisii', $member_id, $device_id, $zk_user_id, $fingerprint_enrolled, $face_enrolled);
        
        if ($stmt->execute()) {
            return [
                'success' => true, 
                'message' => 'Member enrollment record created. Complete enrollment on device.',
                'zk_user_id' => $zk_user_id
            ];
        }
        
        return ['success' => false, 'message' => 'Failed to create enrollment record'];
    }
}
?>
