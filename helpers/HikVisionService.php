<?php
/**
 * HikVision DS-K1T320MFWX Integration Service
 * Handles communication with HikVision face recognition devices
 */
if (!defined('CURL_HTTPAUTH_DIGEST')) {
    define('CURL_HTTPAUTH_DIGEST', 2);
}
class HikVisionService {
    private $conn;
    private $device_id;
    private $ip_address;
    private $port;
    private $username;
    private $password;
    private $base_url;
    
    public function __construct($conn, $device_id = null) {
        $this->conn = $conn;
        if ($device_id) {
            $this->loadDevice($device_id);
        }
    }
    
    /**
     * Load device configuration from database
     */
    private function loadDevice($device_id) {
        $stmt = $this->conn->prepare("SELECT * FROM hikvision_devices WHERE id = ? AND is_active = 1");
        $stmt->bind_param("i", $device_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($device = $result->fetch_assoc()) {
            $this->device_id = $device['id'];
            $this->ip_address = $device['ip_address'];
            $this->port = $device['port'];
            $this->username = $device['username'];
            $this->password = $device['password'];
            $this->base_url = "http://{$this->ip_address}:{$this->port}";
        } else {
            throw new Exception("Device not found or inactive");
        }
    }
    
    /**
     * Test device connection
     */
    public function testConnection() {
        try {
            $response = $this->makeRequest('/ISAPI/System/deviceInfo', 'GET');
            $this->updateDeviceStatus('connected');
            return ['success' => true, 'data' => $response];
        } catch (Exception $e) {
            $this->updateDeviceStatus('error');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get device information
     */
    public function getDeviceInfo() {
        return $this->makeRequest('/ISAPI/System/deviceInfo', 'GET');
    }
    
    /**
     * Add user to device
     */
    public function addUser($member_id, $user_name, $face_data = null) {
        // Generate unique HikVision user ID
        $hikvision_user_id = 'USER_' . str_pad($member_id, 6, '0', STR_PAD_LEFT);
        
        $user_data = [
            'UserInfo' => [
                'employeeNo' => $hikvision_user_id,
                'name' => $user_name,
                'userType' => 'normal',
                'Valid' => [
                    'enable' => true,
                    'beginTime' => date('Y-m-d\TH:i:s'),
                    'endTime' => date('Y-m-d\TH:i:s', strtotime('+10 years'))
                ]
            ]
        ];
        
        try {
            // Add user to device
            $response = $this->makeRequest('/ISAPI/AccessControl/UserInfo/Record', 'POST', $user_data);
            
            // Update member_hikvision_data
            $this->updateMemberEnrollment($member_id, $hikvision_user_id, false);
            
            return ['success' => true, 'hikvision_user_id' => $hikvision_user_id, 'data' => $response];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Enroll face for user
     */
    public function enrollFace($member_id, $face_image_path) {
        $member_data = $this->getMemberHikVisionData($member_id);
        if (!$member_data) {
            return ['success' => false, 'error' => 'Member not enrolled in device'];
        }
        
        $hikvision_user_id = $member_data['hikvision_user_id'];
        
        // Convert image to base64
        $face_data = base64_encode(file_get_contents($face_image_path));
        
        $face_info = [
            'faceLibType' => 'blackFD',
            'FDID' => '1',
            'FPID' => $hikvision_user_id,
            'faceInfo' => [
                'employeeNo' => $hikvision_user_id,
                'faceURL' => 'data:image/jpeg;base64,' . $face_data
            ]
        ];
        
        try {
            $response = $this->makeRequest('/ISAPI/Intelligent/FDLib/FaceDataRecord', 'POST', $face_info);
            
            // Update enrollment status
            $this->updateMemberEnrollment($member_id, $hikvision_user_id, true);
            
            return ['success' => true, 'data' => $response];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get access logs from device
     */
    public function getAccessLogs($start_time = null, $end_time = null) {
        if (!$start_time) {
            $start_time = date('Y-m-d\T00:00:00', strtotime('-1 day'));
        }
        if (!$end_time) {
            $end_time = date('Y-m-d\T23:59:59');
        }
        
        $search_data = [
            'searchID' => uniqid(),
            'searchResultPosition' => 0,
            'maxResults' => 1000,
            'SearchCondition' => [
                'searchTimeType' => 'startTime',
                'startTime' => $start_time,
                'endTime' => $end_time
            ]
        ];
        
        return $this->makeRequest('/ISAPI/AccessControl/AcsEvent', 'POST', $search_data);
    }
    
    /**
     * Sync attendance data from device
     */
    public function syncAttendance($session_id = null) {
        try {
            $logs = $this->getAccessLogs();
            $synced_count = 0;
            $errors = [];
            
            if (isset($logs['AcsEvent']['InfoList'])) {
                foreach ($logs['AcsEvent']['InfoList'] as $log) {
                    $result = $this->processAccessLog($log, $session_id);
                    if ($result['success']) {
                        $synced_count++;
                    } else {
                        $errors[] = $result['error'];
                    }
                }
            }
            
            // Update last sync time
            $this->updateLastSync();
            
            return [
                'success' => true,
                'synced_count' => $synced_count,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process individual access log entry
     */
    private function processAccessLog($log, $session_id = null) {
        try {
            // Store raw log
            $raw_log_id = $this->storeRawLog($log);
            
            // Find member by HikVision user ID
            $member_id = $this->findMemberByHikVisionUserId($log['employeeNoString']);
            
            if (!$member_id) {
                return ['success' => false, 'error' => 'Member not found for user ID: ' . $log['employeeNoString']];
            }
            
            // Create attendance record
            $attendance_data = [
                'session_id' => $session_id,
                'member_id' => $member_id,
                'status' => 'present',
                'sync_source' => 'hikvision',
                'verification_type' => 'face',
                'device_timestamp' => $log['time'],
                'hikvision_raw_log_id' => $raw_log_id
            ];
            
            $this->createAttendanceRecord($attendance_data);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Store raw log data
     */
    private function storeRawLog($log) {
        $stmt = $this->conn->prepare("
            INSERT INTO hikvision_raw_logs 
            (device_id, user_id, event_time, event_type, verification_mode, raw_data) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $user_id = $log['employeeNoString'];
        $event_time = $log['time'];
        $event_type = 'access';
        $verification_mode = 'face';
        $raw_data = json_encode($log);
        
        $stmt->bind_param("isssss", $this->device_id, $user_id, $event_time, $event_type, $verification_mode, $raw_data);
        $stmt->execute();
        
        return $this->conn->insert_id;
    }
    
    /**
     * Find member by HikVision user ID
     */
    private function findMemberByHikVisionUserId($hikvision_user_id) {
        $stmt = $this->conn->prepare("
            SELECT member_id FROM member_hikvision_data 
            WHERE hikvision_user_id = ? AND device_id = ?
        ");
        $stmt->bind_param("si", $hikvision_user_id, $this->device_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return $row['member_id'];
        }
        
        return null;
    }
    
    /**
     * Create attendance record
     */
    private function createAttendanceRecord($data) {
        $stmt = $this->conn->prepare("
            INSERT INTO attendance_records 
            (session_id, member_id, status, sync_source, verification_type, device_timestamp, hikvision_raw_log_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            sync_source = VALUES(sync_source),
            verification_type = VALUES(verification_type),
            device_timestamp = VALUES(device_timestamp),
            hikvision_raw_log_id = VALUES(hikvision_raw_log_id)
        ");
        
        $stmt->bind_param("iissssi", 
            $data['session_id'], 
            $data['member_id'], 
            $data['status'], 
            $data['sync_source'], 
            $data['verification_type'], 
            $data['device_timestamp'], 
            $data['hikvision_raw_log_id']
        );
        
        return $stmt->execute();
    }
    
    /**
     * Update member enrollment data
     */
    private function updateMemberEnrollment($member_id, $hikvision_user_id, $face_enrolled) {
        $stmt = $this->conn->prepare("
            INSERT INTO member_hikvision_data 
            (member_id, device_id, hikvision_user_id, face_enrolled, enrollment_date) 
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
            face_enrolled = VALUES(face_enrolled),
            enrollment_date = IF(VALUES(face_enrolled) = 1 AND face_enrolled = 0, NOW(), enrollment_date)
        ");
        
        $stmt->bind_param("iisi", $member_id, $this->device_id, $hikvision_user_id, $face_enrolled);
        return $stmt->execute();
    }
    
    /**
     * Get member HikVision data
     */
    private function getMemberHikVisionData($member_id) {
        $stmt = $this->conn->prepare("
            SELECT * FROM member_hikvision_data 
            WHERE member_id = ? AND device_id = ?
        ");
        $stmt->bind_param("ii", $member_id, $this->device_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    /**
     * Update device status
     */
    private function updateDeviceStatus($status) {
        $stmt = $this->conn->prepare("
            UPDATE hikvision_devices 
            SET sync_status = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("si", $status, $this->device_id);
        $stmt->execute();
    }
    
    /**
     * Update last sync time
     */
    private function updateLastSync() {
        $stmt = $this->conn->prepare("
            UPDATE hikvision_devices 
            SET last_sync = NOW(), sync_status = 'connected' 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $this->device_id);
        $stmt->execute();
    }
    
    /**
     * Make HTTP request to device
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->base_url . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPAUTH => CURL_HTTPAUTH_DIGEST,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("CURL Error: " . $error);
        }
        
        if ($http_code >= 400) {
            throw new Exception("HTTP Error {$http_code}: " . $response);
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Get all active devices
     */
    public static function getActiveDevices($conn, $church_id = null) {
        $sql = "SELECT * FROM hikvision_devices WHERE is_active = 1";
        $params = [];
        $types = "";
        
        if ($church_id) {
            $sql .= " AND church_id = ?";
            $params[] = $church_id;
            $types .= "i";
        }
        
        $sql .= " ORDER BY device_name";
        
        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
?>
