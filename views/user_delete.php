<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
if (!is_logged_in() || !has_permission('manage_users')) {
    http_response_code(403);
    exit('Forbidden');
}
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$user_id) {
    header('Location: user_list.php?error=invalid');
    exit;
}
// Prevent deleting self or super admin
if ($_SESSION['user_id'] == $user_id) {
    header('Location: user_list.php?error=self');
    exit;
}
// Use transaction to ensure both user and roles are deleted together
$conn->begin_transaction();
try {
    // First delete user roles
    $stmt = $conn->prepare('DELETE FROM user_roles WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
    
    // Then delete the user
    $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
    
    $conn->commit();
    header('Location: user_list.php?deleted=1');
    exit;
} catch (Exception $e) {
    $conn->rollback();
    header('Location: user_list.php?error=db');
    exit;
}
