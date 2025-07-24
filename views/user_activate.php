<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in() || !(isset($_SESSION['role_id']) && ($_SESSION['role_id'] == 1 || has_permission('manage_users')))) {
    http_response_code(403);
    exit('Forbidden');
}
if (!isset($_GET['id'])) {
    http_response_code(400);
    exit('Missing user id');
}
$user_id = intval($_GET['id']);
$user = $conn->query("SELECT * FROM users WHERE id = $user_id")->fetch_assoc();
if (!$user) {
    http_response_code(404);
    exit('User not found');
}
if ($user['status'] !== 'inactive') {
    http_response_code(400);
    exit('User is not inactive');
}
// Activate user
$conn->query("UPDATE users SET status = 'active' WHERE id = $user_id");
header('Location: user_list.php?activated=1');
exit;
