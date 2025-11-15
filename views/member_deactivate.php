<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('deactivate_member')) {
    http_response_code(403);
    die('You do not have permission to deactivate members.');
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
