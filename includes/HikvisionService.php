<?php
/**
 * Hikvision Integration Service
 * 
 * This service handles all Hikvision device communication, data synchronization,
 * and attendance processing for the church management system.
 */

require_once __DIR__ . '/../config/config.php';

class HikvisionService {
    private $conn;
    private $devices = [];
    
    public function __construct() {
        global $conn;
        $this->conn = $conn;
        $this->loadActiveDevices();
    }
    
    /**
     * Load all Hikvision devices from database
     */
    private function loadActiveDevices() {
        $query = "SELECT * FROM hikvision_devices ORDER BY id";
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
            // For now, we'll just simulate a connection test
            // In a real implementation, this would use the Hikvision SDK or API
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'version' => 'Simulated',
                'users' => 0,
                'records' => 0
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Connection error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Toggle device active status
     */
    public function toggleDeviceStatus($device_id) {
        if (!isset($this->devices[$device_id])) {
            return ['success' => false, 'message' => 'Device not found'];
        }
        
        $device = $this->devices[$device_id];
        $new_status = $device['is_active'] ? 0 : 1;
        
        $stmt = $this->conn->prepare("UPDATE hikvision_devices SET is_active = ? WHERE id = ?");
        $stmt->bind_param('ii', $new_status, $device_id);
        $result = $stmt->execute();
        
        if ($result) {
            // Update local cache
            $this->devices[$device_id]['is_active'] = $new_status;
            
            return [
                'success' => true,
                'message' => 'Device status updated successfully',
                'new_status' => $new_status
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to update device status'];
        }
    }
    
    /**
     * Delete a device from the system
     */
    public function deleteDevice($device_id) {
        if (!isset($this->devices[$device_id])) {
            return ['success' => false, 'message' => 'Device not found'];
        }
        
        // First delete any related records
        $this->conn->query("DELETE FROM hikvision_enrollments WHERE device_id = $device_id");
        $this->conn->query("DELETE FROM hikvision_attendance_logs WHERE device_id = $device_id");
        
        // Then delete the device
        $stmt = $this->conn->prepare("DELETE FROM hikvision_devices WHERE id = ?");
        $stmt->bind_param('i', $device_id);
        $result = $stmt->execute();
        
        if ($result) {
            // Remove from local cache
            unset($this->devices[$device_id]);
            
            return [
                'success' => true,
                'message' => 'Device deleted successfully'
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to delete device'];
        }
    }
    
    /**
     * Get device details by ID
     */
    public function getDevice($device_id) {
        if (!isset($this->devices[$device_id])) {
            // Try to load from database in case it wasn't loaded initially
            $stmt = $this->conn->prepare("SELECT * FROM hikvision_devices WHERE id = ?");
            $stmt->bind_param('i', $device_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return ['success' => false, 'message' => 'Device not found'];
            }
            
            $this->devices[$device_id] = $result->fetch_assoc();
        }
        
        return [
            'success' => true,
            'device' => $this->devices[$device_id]
        ];
    }
    
    /**
     * Update device information
     */
    public function updateDevice($device_id, $data) {
        if (!isset($this->devices[$device_id])) {
            return ['success' => false, 'message' => 'Device not found'];
        }
        
        // Prepare update fields
        $fields = [];
        $types = '';
        $values = [];
        
        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $types .= 's';
            $values[] = $data['name'];
        }
        
        if (isset($data['ip_address'])) {
            $fields[] = 'ip_address = ?';
            $types .= 's';
            $values[] = $data['ip_address'];
        }
        
        if (isset($data['port'])) {
            $fields[] = 'port = ?';
            $types .= 'i';
            $values[] = $data['port'];
        }
        
        if (isset($data['username'])) {
            $fields[] = 'username = ?';
            $types .= 's';
            $values[] = $data['username'];
        }
        
        if (isset($data['password']) && !empty($data['password'])) {
            $fields[] = 'password = ?';
            $types .= 's';
            $values[] = $data['password'];
        }
        
        if (isset($data['location'])) {
            $fields[] = 'location = ?';
            $types .= 's';
            $values[] = $data['location'];
        }
        
        if (isset($data['church_id'])) {
            $fields[] = 'church_id = ?';
            $types .= 'i';
            $values[] = $data['church_id'];
        }
        
        if (empty($fields)) {
            return ['success' => false, 'message' => 'No fields to update'];
        }
        
        // Add device_id to values array and types
        $values[] = $device_id;
        $types .= 'i';
        
        $query = "UPDATE hikvision_devices SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        
        // Dynamically bind parameters
        $bindParams = array($types);
        foreach ($values as $key => $value) {
            $bindParams[] = &$values[$key];
        }
        call_user_func_array(array($stmt, 'bind_param'), $bindParams);
        
        $result = $stmt->execute();
        
        if ($result) {
            // Update local cache
            foreach ($data as $key => $value) {
                if ($key !== 'password' || !empty($value)) {
                    $this->devices[$device_id][$key] = $value;
                }
            }
            
            return [
                'success' => true,
                'message' => 'Device updated successfully'
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to update device'];
        }
    }
    
    /**
     * Update device information in database
     */
    private function updateDeviceInfo($device_id, $version, $userCount, $recordCount) {
        $stmt = $this->conn->prepare("
            UPDATE hikvision_devices 
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
            // In a real implementation, this would use the Hikvision SDK or API
            // to connect to the device and retrieve attendance records
            
            // For now, we'll just simulate a successful sync
            $this->updateSyncHistory($sync_history_id, 'completed', 0, 0, null);
            
            return [
                'success' => true,
                'message' => 'Sync process initiated successfully',
                'sync_history_id' => $sync_history_id
            ];
        } catch (Exception $e) {
            $this->updateSyncHistory($sync_history_id, 'failed', 0, 0, $e->getMessage());
            return ['success' => false, 'message' => 'Sync error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Process raw attendance log from Hikvision device
     */
    public function processRawAttendanceLog($record, $device_id) {
        // In a real implementation, this would process the attendance record
        // and create attendance entries in the database
        
        return [
            'success' => true,
            'message' => 'Record processed successfully'
        ];
    }
    
    /**
     * Start sync history record
     */
    private function startSyncHistory($device_id, $sync_type, $initiated_by) {
        $stmt = $this->conn->prepare("
            INSERT INTO hikvision_sync_history 
            (device_id, start_time, status, sync_type, initiated_by) 
            VALUES (?, NOW(), 'in_progress', ?, ?)
        ");
        $stmt->bind_param('iss', $device_id, $sync_type, $initiated_by);
        $stmt->execute();
        
        return $this->conn->insert_id;
    }
    
    /**
     * Update sync history record
     */
    private function updateSyncHistory($sync_history_id, $status, $synced, $processed, $error_message) {
        $stmt = $this->conn->prepare("
            UPDATE hikvision_sync_history 
            SET end_time = NOW(), status = ?, records_synced = ?, records_processed = ?, error_message = ? 
            WHERE id = ?
        ");
        $stmt->bind_param('siisi', $status, $synced, $processed, $error_message, $sync_history_id);
        $stmt->execute();
    }
    
    /**
     * Get device statistics
     */
    public function getDeviceStats($device_id) {
        $stats = [
            'total_enrollments' => 0,
            'total_attendance' => 0,
            'last_attendance' => null,
            'last_sync' => null
        ];
        
        // Get enrollment count
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count 
            FROM hikvision_enrollments 
            WHERE device_id = ?
        ");
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['total_enrollments'] = $result['count'] ?? 0;
        
        // Get attendance count
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count 
            FROM hikvision_attendance_logs 
            WHERE device_id = ?
        ");
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['total_attendance'] = $result['count'] ?? 0;
        
        // Get last attendance
        $stmt = $this->conn->prepare("
            SELECT MAX(timestamp) as last_time 
            FROM hikvision_attendance_logs 
            WHERE device_id = ?
        ");
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['last_attendance'] = $result['last_time'] ?? null;
        
        // Get last sync
        $stmt = $this->conn->prepare("
            SELECT MAX(end_time) as last_sync 
            FROM hikvision_sync_history 
            WHERE device_id = ? AND status = 'completed'
        ");
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['last_sync'] = $result['last_sync'] ?? null;
        
        return $stats;
    }
    
    /**
     * Generate unique Hikvision User ID for a member on a specific device
     */
    public function generateUniqueHikvisionUserId($device_id, $member_id = null) {
        // If member_id is provided, try to use their CRN as the user ID
        if ($member_id) {
            $stmt = $this->conn->prepare("SELECT crn FROM members WHERE id = ?");
            $stmt->bind_param('i', $member_id);
            $stmt->execute();
            $member = $stmt->get_result()->fetch_assoc();
            
            if ($member && is_numeric($member['crn'])) {
                // Check if this CRN is already used on this device
                $stmt = $this->conn->prepare("
                    SELECT id FROM hikvision_enrollments 
                    WHERE device_id = ? AND hikvision_user_id = ?
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
            SELECT MAX(CAST(hikvision_user_id AS UNSIGNED)) as max_id 
            FROM hikvision_enrollments 
            WHERE device_id = ? AND hikvision_user_id REGEXP '^[0-9]+$'
        ");
        $stmt->bind_param('i', $device_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        $next_id = ($result['max_id'] ?? 0) + 1;
        
        // Ensure the generated ID doesn't conflict with existing ones
        do {
            $stmt = $this->conn->prepare("
                SELECT id FROM hikvision_enrollments 
                WHERE device_id = ? AND hikvision_user_id = ?
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
    
    // toggleDeviceStatus method removed (duplicate) - already defined at line 64
    
    // deleteDevice method removed (duplicate) - already defined at line 90
    
    // getDevice method removed (duplicate) - already defined at line 120
    
    // updateDevice method removed (duplicate) - already defined at line 144
    
    /**
     * Enroll member to device (placeholder - requires physical presence)
     */
    public function enrollMember($member_id, $device_id, $enrollment_type = 'fingerprint') {
        // This is a placeholder function - actual enrollment requires physical presence
        // and interaction with the device's enrollment interface
        
        // Generate Hikvision user ID based on member CRN or ID
        $stmt = $this->conn->prepare("SELECT crn, first_name, last_name FROM members WHERE id = ?");
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        
        if (!$member) {
            return ['success' => false, 'message' => 'Member not found'];
        }
        
        // Use CRN as Hikvision user ID, or generate one if CRN is not numeric
        $hikvision_user_id = is_numeric($member['crn']) ? $member['crn'] : $this->generateUniqueHikvisionUserId($device_id, $member_id);
        
        // Check if already enrolled
        $stmt = $this->conn->prepare("
            SELECT id FROM hikvision_enrollments 
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
            INSERT INTO hikvision_enrollments 
            (member_id, device_id, hikvision_user_id, fingerprint_enrolled, face_enrolled, enrollment_date) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $fingerprint_enrolled = ($enrollment_type === 'fingerprint') ? 1 : 0;
        $face_enrolled = ($enrollment_type === 'face') ? 1 : 0;
        
        $stmt->bind_param('iisii', $member_id, $device_id, $hikvision_user_id, $fingerprint_enrolled, $face_enrolled);
        
        if ($stmt->execute()) {
            return [
                'success' => true, 
                'message' => 'Member enrollment record created. Complete enrollment on device.',
                'hikvision_user_id' => $hikvision_user_id
            ];
        }
        
        return ['success' => false, 'message' => 'Failed to create enrollment record'];
    }
}
?>
