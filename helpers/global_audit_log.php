<?php
// Global helper to log all major actions for audit_log table
require_once __DIR__ . '/../config/config.php';

function log_activity($action, $entity_type = '', $entity_id = null, $details = '', $user_id = null) {
    global $conn;
    if (!$conn) return false;
    if (!$user_id && isset($_SESSION['user_id'])) $user_id = $_SESSION['user_id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ississ', $user_id, $action, $entity_type, $entity_id, $details, $ip);
    return $stmt->execute();
}
