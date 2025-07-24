<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
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
$stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
$stmt->bind_param('i', $user_id);
if ($stmt->execute()) {
    header('Location: user_list.php?deleted=1');
    exit;
} else {
    header('Location: user_list.php?error=db');
    exit;
}
