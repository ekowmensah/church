<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';
require_once __DIR__.'/../helpers/church_helper.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check permissions with super admin bypass
$is_super_admin = isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1;
if (!$is_super_admin && !has_permission('mark_attendance')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$device_id = isset($_GET['device_id']) ? intval($_GET['device_id']) : 0;

if (!$device_id) {
    echo json_encode(['success' => false, 'message' => 'Device ID is required']);
    exit;
}

// Get device data with church information
// Super admin can access any device, others only their church's devices
$is_super_admin = isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1;

if ($is_super_admin) {
    $stmt = $conn->prepare("
        SELECT zd.*, c.name as church_name 
        FROM zkteco_devices zd 
        JOIN churches c ON zd.church_id = c.id 
        WHERE zd.id = ?
    ");
    $stmt->bind_param('i', $device_id);
} else {
    $church_id = get_user_church_id($conn);
    $stmt = $conn->prepare("
        SELECT zd.*, c.name as church_name 
        FROM zkteco_devices zd 
        JOIN churches c ON zd.church_id = c.id 
        WHERE zd.id = ? AND zd.church_id = ?
    ");
    $stmt->bind_param('ii', $device_id, $church_id);
}
$stmt->execute();
$result = $stmt->get_result();

if ($device = $result->fetch_assoc()) {
    echo json_encode([
        'success' => true,
        'device' => $device
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Device not found'
    ]);
}

$stmt->close();
?>
