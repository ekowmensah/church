<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in() || !(isset($_SESSION['role_id']) && ($_SESSION['role_id'] == 1 || has_permission('edit_member')))) {
    http_response_code(403);
    exit('Forbidden');
}
if (!isset($_GET['id'])) {
    http_response_code(400);
    exit('Missing member id');
}
$member_id = intval($_GET['id']);
$member = $conn->query("SELECT * FROM members WHERE id = $member_id")->fetch_assoc();
if (!$member) {
    http_response_code(404);
    exit('Member not found');
}
if ($member['status'] !== 'pending' && empty($member['deactivated_at'])) {
    http_response_code(400);
    exit('Member is not deactivated');
}
// Activate member
$conn->query("UPDATE members SET status = 'active', deactivated_at = NULL WHERE id = $member_id");
header('Location: member_list.php?activated=1');
exit;
