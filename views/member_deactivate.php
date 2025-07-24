<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

// Only allow logged-in users with correct permission
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$is_super_admin = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
$can_edit = $is_super_admin || (function_exists('has_permission') && has_permission('edit_member'));
if (!$can_edit) {
    die('No permission to deactivate member.');
}
$id = intval($_GET['id'] ?? 0);
if (!$id) {
    die('Invalid member ID.');
}
$stmt = $conn->prepare('UPDATE members SET status = ?, deactivated_at = NOW() WHERE id = ?');
$new_status = 'de-activated';
$stmt->bind_param('si', $new_status, $id);
$stmt->execute();
$stmt->close();
header('Location: member_list.php?deactivated=1');
exit;
