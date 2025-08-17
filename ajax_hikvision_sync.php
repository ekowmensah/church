<?php
require_once 'includes/admin_auth.php';
require_once 'config/database.php';
require_once 'helpers/HikVisionService.php';

header('Content-Type: application/json');

// Check permissions
if (!$is_super_admin && !has_permission('manage_hikvision_devices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$action = $_POST['action'] ?? '';
$device_id = $_POST['device_id'] ?? null;

if (!$device_id) {
    echo json_encode(['success' => false, 'error' => 'Device ID required']);
    exit;
}

try {
    $service = new HikVisionService($conn, $device_id);
    
    switch ($action) {
        case 'test_connection':
            $result = $service->testConnection();
            echo json_encode($result);
            break;
            
        case 'sync_attendance':
            $session_id = $_POST['session_id'] ?? null;
            $result = $service->syncAttendance($session_id);
            
            if ($result['success']) {
                $response = [
                    'success' => true,
                    'message' => "Sync completed successfully",
                    'data' => [
                        'synced_count' => $result['synced_count'],
                        'errors' => $result['errors']
                    ]
                ];
            } else {
                $response = [
                    'success' => false,
                    'error' => $result['error']
                ];
            }
            
            echo json_encode($response);
            break;
            
        case 'get_device_info':
            $result = $service->getDeviceInfo();
            echo json_encode(['success' => true, 'data' => $result]);
            break;
            
        case 'enroll_member':
            $member_id = $_POST['member_id'] ?? null;
            if (!$member_id) {
                echo json_encode(['success' => false, 'error' => 'Member ID required']);
                exit;
            }
            
            // Get member name
            $member_stmt = $conn->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
            $member_stmt->bind_param("i", $member_id);
            $member_stmt->execute();
            $member = $member_stmt->get_result()->fetch_assoc();
            
            if (!$member) {
                echo json_encode(['success' => false, 'error' => 'Member not found']);
                exit;
            }
            
            $full_name = $member['first_name'] . ' ' . $member['last_name'];
            $result = $service->addUser($member_id, $full_name);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
