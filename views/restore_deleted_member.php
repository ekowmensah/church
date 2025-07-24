<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in() || (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) && !has_permission('manage_members'))) {
    http_response_code(403);
    die('You do not have permission to restore members.');
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id > 0) {
    // Fetch deleted member
    $member = $conn->query("SELECT * FROM deleted_members WHERE id = $id")->fetch_assoc();
    if ($member) {
        // Restore: update status in members to 'pending' (not de-activated)
        $update = $conn->query("UPDATE members SET status = 'pending' WHERE id = $id");
        if ($update) {
            // Remove from deleted_members so they no longer show as deleted
            $conn->query("DELETE FROM deleted_members WHERE id = $id");
            $msg = urlencode('Member restored as pending.');
            header('Location: deleted_members_list.php?restored=1&info=' . $msg);
            exit;
        } else {
            $msg = urlencode('Failed to restore member: ' . $conn->error);
            header('Location: deleted_members_list.php?error=' . $msg);
            exit;
        }
    }
}
header('Location: deleted_members_list.php?error=Invalid+member+ID');
exit;
