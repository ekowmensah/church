<?php
// Helper for writing audit logs
require_once __DIR__ . '/../config/config.php';
function write_audit_log($action, $entity_type, $entity_id = null, $details = '', $user_id = null) {
    global $conn;
    if (!$conn) return false;
    $stmt = $conn->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issis', $user_id, $action, $entity_type, $entity_id, $details);
    return $stmt->execute();
}
