<?php
// api/hikvision/map_user.php
// Handles POST mapping of a device user to a member
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth.php';
session_start();
if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed.";
    exit;
}
$device_id = $_POST['device_id'] ?? '';
$hikvision_user_id = $_POST['hikvision_user_id'] ?? '';
$member_id = $_POST['member_id'] ?? '';
if (!$device_id || !$hikvision_user_id || !$member_id) {
    http_response_code(400);
    echo "Missing required fields.";
    exit;
}
$conn = get_db_connection();
$stmt = $conn->prepare('UPDATE member_hikvision_data SET member_id = ? WHERE device_id = ? AND hikvision_user_id = ?');
$stmt->bind_param('iss', $member_id, $device_id, $hikvision_user_id);
$stmt->execute();
$stmt->close();
$conn->close();
header('Location: /views/member_hikvision_mapping.php?success=1');
exit;
